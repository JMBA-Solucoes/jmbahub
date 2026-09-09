<?php

/**
 * Devolve a prévia de um modelo de Acompanhamento/Tarefa/Solução — conteúdo
 * já renderizado pelo motor de templates do GLPI (Twig, com o chamado real
 * como contexto pras variáveis tipo ##ticket.title## / {{ticket.title}}) —
 * pro dropdown "Usar modelo" do app (Entrega paga "Modelos de Interação").
 *
 * A REST API padrão do GLPI não expõe isso: um GET simples em
 * TaskTemplate/{id} devolve o `content` cru, com as variáveis sem resolver.
 * A aplicação de fato do modelo (que resolve tudo isso e ainda aplica os
 * demais campos do modelo — categoria, privado, grupo etc. — incluindo a
 * cascata de Motivo de pendência) já acontece 100% dentro do próprio GLPI
 * quando o backend Java manda `_itilfollowuptemplates_id` /
 * `_tasktemplates_id` / `_solutiontemplates_id` no POST de criação
 * (ITILFollowup::prepareInputForAdd / CommonITILTask::prepareInputForAdd /
 * ITILSolution::prepareInputForAdd, confirmado no código-fonte do GLPI
 * 11.0.8) — não reimplementamos nada disso aqui. Este endpoint só serve pra
 * pré-visualizar o conteúdo antes do envio, chamando o mesmo
 * `AbstractITILChildTemplate::getRenderedContent()` que o core usa.
 *
 * URL: GET {glpiUrl}/plugins/jmbahub/TemplatePreview/{type}/{templateId}
 *        ?ticketId=&glpiUserId=&activeEntityId=&activeEntityRecursive=&activeProfileId=
 * Header: X-GLPI-App-Secret (mesmo secret de checkuser.php/FormStructure)
 */

namespace GlpiPlugin\Jmbahub\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use ITILFollowupTemplate;
use SolutionTemplate;
use TaskTemplate;
use Ticket;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TemplatePreviewController extends AbstractController
{
    use NativeFormsAuthTrait;

    private const TEMPLATE_CLASSES = [
        'followup' => ITILFollowupTemplate::class,
        'task'     => TaskTemplate::class,
        'solution' => SolutionTemplate::class,
    ];

    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)] // sem sessão prévia — este controller cria uma
    #[Route(
        "/TemplatePreview/{type}/{templateId}",
        name: "jmbahub_template_preview",
        methods: ['GET'],
        requirements: ['templateId' => '\d+'],
    )]
    public function __invoke(Request $request): Response
    {
        $type = (string) $request->get('type');
        if (!array_key_exists($type, self::TEMPLATE_CLASSES)) {
            return new JsonResponse(['error' => 'Tipo de modelo inválido.'], 400);
        }

        $ticketId = (int) $request->query->get('ticketId', 0);
        $glpiUserId = (int) $request->query->get('glpiUserId', 0);
        $activeEntityId = $request->query->has('activeEntityId')
            ? (int) $request->query->get('activeEntityId') : null;
        $activeEntityRecursive = $request->query->has('activeEntityRecursive')
            ? filter_var($request->query->get('activeEntityRecursive'), FILTER_VALIDATE_BOOLEAN) : null;
        $activeProfileId = $request->query->has('activeProfileId')
            ? (int) $request->query->get('activeProfileId') : null;

        $authError = $this->authenticateFromRequest(
            $request, $glpiUserId, $activeEntityId, $activeEntityRecursive, $activeProfileId
        );
        if ($authError !== null) {
            return $authError;
        }

        $ticket = new Ticket();
        if ($ticketId <= 0 || !$ticket->getFromDB($ticketId)) {
            return new JsonResponse(['error' => 'Chamado não encontrado.'], 404);
        }
        if (!$ticket->can($ticketId, READ)) {
            return new JsonResponse(['error' => 'Acesso negado ao chamado.'], 403);
        }

        $templateClass = self::TEMPLATE_CLASSES[$type];
        $template = new $templateClass();
        if (!$template->getFromDB((int) $request->get('templateId'))) {
            return new JsonResponse(['error' => 'Modelo não encontrado.'], 404);
        }

        return new JsonResponse([
            'id'      => (int) $template->fields['id'],
            'name'    => $template->fields['name'] ?? '',
            'content' => $template->getRenderedContent($ticket),
            'fields'  => $this->serializeFields($type, $template),
        ]);
    }

    /**
     * Campos adicionais do modelo (fora `content`), no mesmo shape que
     * `AddFollowupRequest`/`AddTaskRequest`/`AddSolutionRequest` do backend
     * Java vão usar mais pra frente — hoje só informativos pro app, já que
     * quem de fato os aplica no acompanhamento/tarefa/solução criado é o
     * próprio GLPI (via `_xxxtemplates_id` no POST, ver cabeçalho do
     * arquivo), não este endpoint.
     */
    private function serializeFields(string $type, $template): array
    {
        $fields = $template->fields;
        return match ($type) {
            'followup' => [
                'isPrivate'       => (bool) ($fields['is_private'] ?? false),
                'requestTypeId'   => (int) ($fields['requesttypes_id'] ?? 0),
                'pendingReasonId' => (int) ($fields['pendingreasons_id'] ?? 0),
            ],
            'task' => [
                'taskCategoryId'  => (int) ($fields['taskcategories_id'] ?? 0),
                'state'           => (int) ($fields['state'] ?? 0),
                'isPrivate'       => (bool) ($fields['is_private'] ?? false),
                'actiontime'      => (int) ($fields['actiontime'] ?? 0),
                'usersIdTech'     => (int) ($fields['users_id_tech'] ?? 0),
                'groupsIdTech'    => (int) ($fields['groups_id_tech'] ?? 0),
                'pendingReasonId' => (int) ($fields['pendingreasons_id'] ?? 0),
            ],
            'solution' => [
                'solutionTypeId' => (int) ($fields['solutiontypes_id'] ?? 0),
            ],
            default => [],
        };
    }
}
