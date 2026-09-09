<?php

namespace GlpiPlugin\Jmbahub;

use CommonDBTM;
use CommonITILActor;
use Config;
use ITILFollowup;
use ITILSolution;
use RuntimeException;
use Ticket;
use Ticket_User;
use TicketTask;
use Toolbox;

/** Publica eventos de chamados diretamente na API, sem glpi_webhooks. */
final class DirectNotificationSender
{
    public static function onItemAdd(CommonDBTM $item): CommonDBTM
    {
        try {
            if ($item instanceof Ticket) {
                self::sendConfiguredEvent((int) $item->getID(), 'ticket_created', 'NEW_TICKET');
            } elseif ($item instanceof Ticket_User) {
                $actorType = (int) ($item->fields['type'] ?? 0);
                if ($actorType === CommonITILActor::ASSIGN) {
                    self::sendConfiguredEvent((int) ($item->fields['tickets_id'] ?? 0), 'technician_assigned', 'NEW_TICKET');
                } elseif ($actorType === CommonITILActor::REQUESTER) {
                    self::sendNewActorIfConfigured($item, 'requester');
                } elseif ($actorType === CommonITILActor::OBSERVER) {
                    self::sendNewActorIfConfigured($item, 'observer');
                }
            } elseif ($item instanceof ITILFollowup && ($item->fields['itemtype'] ?? '') === Ticket::class) {
                self::sendConfiguredEvent((int) ($item->fields['items_id'] ?? 0), 'followup_added', 'TICKET_FOLLOWUP');
            } elseif ($item instanceof TicketTask) {
                self::sendConfiguredEvent((int) ($item->fields['tickets_id'] ?? 0), 'task_changed', 'TICKET_FOLLOWUP');
            } elseif ($item instanceof ITILSolution && ($item->fields['itemtype'] ?? '') === Ticket::class) {
                self::sendConfiguredEvent((int) ($item->fields['items_id'] ?? 0), 'solution_added', 'TICKET_FOLLOWUP');
            }
        } catch (\Throwable $error) {
            self::recordFailure($error);
        }
        return $item;
    }

    public static function onItemUpdate(CommonDBTM $item): CommonDBTM
    {
        try {
            if ($item instanceof TicketTask) {
                self::sendConfiguredEvent((int) ($item->fields['tickets_id'] ?? 0), 'task_changed', 'TICKET_FOLLOWUP');
            } elseif ($item instanceof Ticket && in_array('status', $item->updates ?? [], true)) {
                $status = (int) ($item->fields['status'] ?? 0);
                $event = in_array($status, [Ticket::SOLVED, Ticket::CLOSED], true) ? 'ticket_closed' : 'status_changed';
                self::sendConfiguredEvent((int) $item->getID(), $event, 'TICKET_FOLLOWUP');
            }
        } catch (\Throwable $error) {
            self::recordFailure($error);
        }
        return $item;
    }

    private static function sendConfiguredEvent(int $ticketId, string $event, string $apiEventType): void
    {
        $matrix = PluginConfig::getNotificationMatrix();
        $roleTypes = [
            'requester' => CommonITILActor::REQUESTER,
            'observer' => CommonITILActor::OBSERVER,
            'technician' => CommonITILActor::ASSIGN,
        ];
        $recipientIds = [];
        foreach ($roleTypes as $role => $type) {
            if (!empty($matrix[$event][$role])) {
                $recipientIds = array_merge($recipientIds, self::actorIds($ticketId, $type));
            }
        }
        self::sendTicket($ticketId, $apiEventType, $recipientIds);
    }

    private static function sendNewActorIfConfigured(Ticket_User $actor, string $role): void
    {
        $matrix = PluginConfig::getNotificationMatrix();
        if (!empty($matrix['ticket_created'][$role])) {
            self::sendTicket(
                (int) ($actor->fields['tickets_id'] ?? 0),
                'NEW_TICKET',
                [(int) ($actor->fields['users_id'] ?? 0)]
            );
        }
    }

    /** @param list<int>|null $recipientIds */
    public static function sendTicket(int $ticketId, string $eventType, ?array $recipientIds = null): void
    {
        if ($ticketId <= 0) {
            return;
        }
        if ($recipientIds !== null) {
            $recipientIds = array_values(array_unique(array_filter(array_map('intval', $recipientIds))));
            if ($recipientIds === []) {
                return;
            }
        }
        $transport = PluginConfig::getNotificationTransport();
        if ($transport === null) {
            throw new RuntimeException('Transporte de notificacoes nao provisionado.');
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB($ticketId)) {
            throw new RuntimeException('Chamado nao encontrado para notificacao.');
        }
        $recipientIds ??= self::assignedTechnicianIds($ticketId);
        $recipientIds = array_values(array_unique(array_filter(array_map('intval', $recipientIds))));
        if ($recipientIds === []) {
            return;
        }

        $payload = json_encode([
            'event_type' => $eventType,
            'ticket_id' => (string) $ticketId,
            'ticket_title' => (string) ($ticket->fields['name'] ?? ''),
            'ticket_date_mod' => (string) ($ticket->fields['date_mod'] ?? ''),
            'glpi_url' => PluginConfig::getGlpiBaseUrl(),
            'actors' => implode('', array_map(static fn (int $id): string => '2:' . $id . ';', $recipientIds)),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $payload . $timestamp, $transport['secret']);
        $response = Toolbox::getGuzzleClient(['timeout' => 5])->post($transport['endpoint'], [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-GLPI-signature' => $signature,
                'X-GLPI-timestamp' => $timestamp,
            ],
            'body' => $payload,
        ]);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new RuntimeException('API de notificacoes respondeu HTTP ' . $response->getStatusCode() . '.');
        }
    }

    /** @return list<int> */
    private static function assignedTechnicianIds(int $ticketId): array
    {
        return self::actorIds($ticketId, CommonITILActor::ASSIGN);
    }

    /** @return list<int> */
    private static function actorIds(int $ticketId, int $type): array
    {
        global $DB;
        $ids = [];
        $iterator = $DB->request([
            'SELECT' => ['users_id'],
            'FROM' => 'glpi_tickets_users',
            'WHERE' => ['tickets_id' => $ticketId, 'type' => $type],
        ]);
        foreach ($iterator as $row) {
            $ids[] = (int) $row['users_id'];
        }
        return $ids;
    }

    private static function recordFailure(\Throwable $error): void
    {
        Config::setConfigurationValues('plugin:jmbahub', [
            'notifications_last_delivery_error' => mb_substr($error->getMessage(), 0, 500),
        ]);
        Toolbox::logInFile('php-errors', 'jmbahub: envio direto de notificacao falhou: ' . $error->getMessage());
    }
}
