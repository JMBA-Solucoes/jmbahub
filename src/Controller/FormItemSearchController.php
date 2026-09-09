<?php

/**
 * Busca paginada de itens (Categoria/Localização/ativos genéricos etc.) pra
 * uma pergunta do tipo Item/ItemDropdown — Entrega C4 da renderização
 * nativa dos formulários. É o caso mais complicado do catálogo: a busca
 * nativa do GLPI (`Dropdown::show()` -> `ajax/getDropdownValue.php`) exige
 * um token IDOR (`Session::validateIDOR()`), gerado normalmente no render
 * HTML da página. Investigando `Session::getNewIDORToken()`, o token não é
 * amarrado a um render específico — só precisa de uma sessão autenticada
 * (que este controller já tem via `NativeFormsAuthTrait`), então o próprio
 * plugin pode mintar o token dele mesmo, sem precisar reimplementar a
 * lógica de restrição (`categories_filter`, `root_items_id`/`subtree_depth`,
 * `is_helpdeskvisible`) manualmente — delega 100% pra
 * `Dropdown::getDropdownValue()`, a mesma função usada pela ponte AJAX
 * nativa (`ajax/getDropdownValue.php`).
 *
 * URL: GET {glpiUrl}/plugins/jmbahub/FormItems/{formId}/{questionId}
 *        ?glpiUserId=&activeEntityId=&activeEntityRecursive=&activeProfileId=&q=&page=&pageSize=
 * Header: X-GLPI-App-Secret (mesmo secret de checkuser.php/Consume/FormStructure/FormSubmit/FormActors)
 */

namespace GlpiPlugin\Jmbahub\Controller;

use Dropdown;
use Glpi\Controller\AbstractController;
use Glpi\Form\Form;
use Glpi\Form\Question;
use Glpi\Form\QuestionType\QuestionTypeItem;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FormItemSearchController extends AbstractController
{
    use NativeFormsAuthTrait;

    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)] // sem sessão prévia — este controller cria uma
    #[Route(
        "/FormItems/{formId}/{questionId}",
        name: "jmbahub_form_item_search",
        methods: ['GET'],
        requirements: ['formId' => '\d+', 'questionId' => '\d+'],
    )]
    public function __invoke(Request $request): Response
    {
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

        $form = Form::getById((int) $request->get('formId'));
        if (!$form instanceof Form) {
            return new JsonResponse(['error' => 'Formulário não encontrado.'], 404);
        }

        $question = Question::getById((int) $request->get('questionId'));
        if (!$question instanceof Question || $question->getForm()->getID() !== $form->getID()) {
            return new JsonResponse(['error' => 'Pergunta não encontrada.'], 404);
        }

        $questionType = $question->getQuestionType();
        if (!$questionType instanceof QuestionTypeItem) {
            return new JsonResponse(['error' => 'Pergunta não é do tipo Objeto/Dropdown.'], 400);
        }

        $itemtype = $questionType->getDefaultValueItemtype($question);
        if ($itemtype === null || !class_exists($itemtype)) {
            return new JsonResponse(['error' => 'Pergunta sem tipo de objeto configurado.'], 400);
        }

        $searchText = (string) $request->query->get('q', '');
        $page = max(1, (int) $request->query->get('page', 1));
        $pageSize = (int) $request->query->get('pageSize', 20);
        if ($pageSize <= 0) {
            $pageSize = 20;
        }

        // Mesma restrição que o core aplica no render do formulário
        // (QuestionTypeItem/QuestionTypeItemDropdown::getDropdownRestrictionParams,
        // já reaproveitada tal qual — nenhuma regra nova aqui). O condition
        // precisa entrar em $_SESSION['glpicondition'] (mesmo mecanismo de
        // Dropdown::show()) porque Dropdown::getDropdownValue() só aceita
        // 'condition' como chave de sessão, não como array direto.
        $restriction = $questionType->getDropdownRestrictionParams($question);
        $conditionKey = !empty($restriction['WHERE'] ?? []) ? Dropdown::addNewCondition($restriction) : '';

        $post = [
            'itemtype'             => $itemtype,
            'searchText'           => $searchText,
            'page'                 => $page,
            'page_limit'           => $pageSize,
            'displaywith'          => [],
            'condition'            => $conditionKey,
            'permit_select_parent' => false,
            'display_emptychoice'  => false,
        ];
        $post['_idor_token'] = Session::getNewIDORToken($itemtype, [
            'displaywith' => $post['displaywith'],
            'condition'   => $post['condition'],
        ]);

        $raw = Dropdown::getDropdownValue($post, false);
        if ($raw === false) {
            return new JsonResponse(['error' => 'Falha ao buscar itens no GLPI.'], 502);
        }

        return new JsonResponse($this->formatResult($raw, $itemtype));
    }

    /**
     * `Dropdown::getDropdownValue()` devolve resultado plano
     * (`[{id,text,title}, ...]`) quando a entidade ativa é só uma, ou
     * agrupado por entidade (`[{text, children:[...], itemtype:'Entity'}]`)
     * quando o item abrange várias entidades (ex.: Super-Admin recursivo).
     * Normaliza os dois casos pro mesmo shape `{groups: [{label, options}]}`
     * usado por FormActorsSearchController (Entrega C3).
     */
    private function formatResult(array $raw, string $itemtype): array
    {
        $datas = $raw['results'] ?? [];
        $isGrouped = count($datas) > 0 && array_key_exists('children', $datas[0]);

        if ($isGrouped) {
            $groups = array_map(
                fn (array $group) => [
                    'label' => $group['text'] ?? '',
                    'options' => array_map([$this, 'formatOption'], $group['children'] ?? []),
                ],
                $datas
            );
        } else {
            $groups = [[
                'label' => $itemtype::getTypeName(1),
                'options' => array_map([$this, 'formatOption'], $datas),
            ]];
        }

        return ['groups' => array_values($groups), 'count' => (int) ($raw['count'] ?? 0)];
    }

    /**
     * `disabled: true` marca linhas de "breadcrumb" de árvore (categoria
     * pai só pra dar contexto, não selecionável — `permit_select_parent`
     * é `false`, mesmo default do `end_user_template.html.twig` do core).
     */
    private function formatOption(array $option): array
    {
        return [
            'id' => (string) ($option['id'] ?? ''),
            'label' => $option['text'] ?? '',
            'subtitle' => $option['title'] ?? null,
            'level' => isset($option['level']) ? (int) $option['level'] : null,
            'selectable' => !($option['disabled'] ?? false),
        ];
    }
}
