<?php

/**
 * Gerencia quais usuários GLPI têm acesso ao app mobile.
 */
class PluginJmbahubAppuser extends CommonDBTM {

    public static function getIcon(): string {
        return 'jmba-hub-menu-logo';
    }

    public static $rightname = 'config';

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string {
        if ($item instanceof User) {
            return self::createTabEntry(__('JMBA Hub', 'jmbahub'));
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool {
        if ($item instanceof User) {
            self::showForUser($item);
        }
        return true;
    }

    /**
     * O destino padrão do menu (Administração > JMBA Hub) é a busca genérica
     * desta classe — vazia até algum usuário ser habilitado, o que confunde
     * quem clica pela primeira vez. Redireciona pra tela do hub de módulos
     * (front/config.form.php). Gerenciar quem tem acesso ao app passou a ser
     * feito na listagem nativa de Usuários (filtro + ação em massa "Habilitar/
     * Desabilitar acesso ao app", ver plugin_jmbahub_getAddSearchOptionsNew()/
     * plugin_jmbahub_MassiveActions() em hook.php) — não mais por aqui.
     */
    public static function getMenuContent(): array {
        $menu = parent::getMenuContent();
        if (is_array($menu) && isset($menu['page'])) {
            $hubUrl = Plugin::getWebDir('jmbahub') . '/front/config.form.php';
            $menu['page']            = $hubUrl;
            $menu['links']['search'] = $hubUrl;
        }
        return $menu;
    }

    /**
     * Exibe o painel de controle do app na aba do usuário.
     */
    public static function showForUser(User $user): void {
        $appuser = new self();
        $userId  = $user->getID();
        $exists  = $appuser->getFromDBByCrit(['users_id' => $userId]);
        $enabled = $exists && $appuser->fields['is_active'];

        echo "<div class='spaced'>";
        echo "<form method='post' action='" . Plugin::getWebDir('jmbahub') . "/front/appuser.form.php'>";
        echo "<input type='hidden' name='users_id' value='{$userId}'>";
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

        echo "<table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_1'>";
        echo "<th colspan='2'>" . __('Acesso ao App Mobile', 'jmbahub') . "</th>";
        echo "</tr>";
        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Usuário habilitado para o app', 'jmbahub') . "</td>";
        echo "<td>";
        Dropdown::showYesNo('is_active', $enabled ? 1 : 0);
        echo "</td>";
        echo "</tr>";
        echo "<tr>";
        echo "<td colspan='2' class='center'>";
        echo "<input type='submit' name='save' value='" . __('Salvar') . "' class='btn btn-primary'>";
        echo "</td>";
        echo "</tr>";
        echo "</table>";
        Html::closeForm();
        echo "</div>";
    }

    /**
     * Ação em massa "Habilitar/Desabilitar acesso ao app" na listagem nativa
     * de Usuários — registrada via plugin_jmbahub_MassiveActions() (hook.php),
     * só quando o módulo "Controle de acesso ao app" está ligado.
     */
    public static function getMassiveActionsForItemtype(
        array &$actions,
        $itemtype,
        $is_deleted = false,
        ?CommonDBTM $checkitem = null
    ): void {
        if ($itemtype !== User::class) {
            return;
        }
        $prefix = self::class . MassiveAction::CLASS_ACTION_SEPARATOR;
        $actions[$prefix . 'enable_access']  = __('Habilitar acesso ao app (JMBA Hub)', 'jmbahub');
        $actions[$prefix . 'disable_access'] = __('Desabilitar acesso ao app (JMBA Hub)', 'jmbahub');
    }

    public static function processMassiveActionsForOneItemtype(
        MassiveAction $ma,
        CommonDBTM $item,
        array $ids
    ): void {
        $action = $ma->getAction();
        if (!in_array($action, ['enable_access', 'disable_access'], true)) {
            return;
        }

        $isActive = $action === 'enable_access' ? 1 : 0;
        foreach ($ids as $id) {
            $ok = self::setActiveForUser((int) $id, $isActive);
            $ma->itemDone($item->getType(), $id, $ok ? MassiveAction::ACTION_OK : MassiveAction::ACTION_KO);
        }
    }

    /**
     * Cria ou atualiza a linha de habilitação do usuário — mesma lógica de
     * front/appuser.form.php, reaproveitada pela ação em massa acima.
     */
    private static function setActiveForUser(int $usersId, int $isActive): bool {
        $appuser = new self();
        $exists  = $appuser->getFromDBByCrit(['users_id' => $usersId]);

        if ($exists) {
            return (bool) $appuser->update([
                'id'        => $appuser->getID(),
                'is_active' => $isActive,
                'date_mod'  => date('Y-m-d H:i:s'),
            ]);
        }

        return (bool) $appuser->add([
            'users_id'      => $usersId,
            'is_active'     => $isActive,
            'date_creation' => date('Y-m-d H:i:s'),
            'date_mod'      => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Verifica se um usuário está habilitado para o app.
     */
    public static function isUserEnabled(int $usersId): bool {
        global $DB;

        $iterator = $DB->request([
            'FROM'   => 'glpi_plugin_jmbahub_appusers',
            'WHERE'  => [
                'users_id' => $usersId,
                'is_active' => 1,
            ],
            'LIMIT' => 1,
        ]);

        return $iterator->count() > 0;
    }

    public static function getTypeName($nb = 0): string {
        return __('JMBA Hub', 'jmbahub');
    }

    /**
     * Permite que qualquer usuário autenticado consulte seus próprios dados.
     * Necessário para que a REST API de busca funcione com qualquer perfil.
     */
    public static function canView(): bool {
        return Session::getLoginUserID() !== false;
    }

    /**
     * Define os campos pesquisáveis via GLPI REST search API.
     * Formato rawSearchOptions() é o padrão do GLPI 10.x.
     * id=2 → users_id | id=3 → is_active
     */
    public function rawSearchOptions(): array {
        $options = parent::rawSearchOptions();

        $options[] = [
            'id'       => 2,
            'table'    => $this->getTable(),
            'field'    => 'users_id',
            'name'     => __('Usuário', 'jmbahub'),
            'datatype' => 'itemlink',
            'itemtype' => 'User',
        ];

        $options[] = [
            'id'       => 3,
            'table'    => $this->getTable(),
            'field'    => 'is_active',
            'name'     => __('Habilitado', 'jmbahub'),
            'datatype' => 'bool',
        ];

        return $options;
    }
}
