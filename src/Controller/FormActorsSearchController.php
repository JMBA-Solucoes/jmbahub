<?php

/**
 * Busca paginada de atores (Requerente/Atribuído/Observador) pra uma
 * pergunta do tipo Actors — Entrega C3 da renderização nativa dos
 * formulários. Diferente de RequestType/UserDevice (Entrega C1/C2), essa
 * lista não dá pra pré-carregar inteira no GET da estrutura (pode ter
 * milhares de usuários) — o app busca sob demanda enquanto o usuário digita.
 *
 * Não reimplementa a busca: delega pra `Glpi\Form\Dropdown\FormActorsDropdown
 * ::fetchValues()`, a mesma classe usada pelo core em
 * `Glpi\Controller\Form\QuestionActorsDropdownController` (rota
 * `/Form/Question/ActorsDropdown` do próprio GLPI). Só remapeia o resultado
 * pra um shape mais simples de consumir no app (grupos por itemtype com
 * `label`/`options`, em vez do formato Select2 cru).
 *
 * URL: GET {glpiUrl}/plugins/jmbahub/FormActors/{formId}/{questionId}
 *        ?glpiUserId=&activeEntityId=&activeEntityRecursive=&activeProfileId=&q=&page=&pageSize=
 * Header: X-GLPI-App-Secret (mesmo secret de checkuser.php/Consume/FormStructure/FormSubmit)
 */

namespace GlpiPlugin\Jmbahub\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Form\Dropdown\FormActorsDropdown;
use Glpi\Form\Form;
use Glpi\Form\Question;
use Glpi\Form\QuestionType\AbstractQuestionTypeActors;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FormActorsSearchController extends AbstractController
{
    use NativeFormsAuthTrait;

    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)] // sem sessão prévia — este controller cria uma
    #[Route(
        "/FormActors/{formId}/{questionId}",
        name: "jmbahub_form_actors_search",
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
        if (!$questionType instanceof AbstractQuestionTypeActors) {
            return new JsonResponse(['error' => 'Pergunta não é do tipo Ator.'], 400);
        }

        // Mesmo filtro aplicado pelo core em
        // AbstractQuestionTypeActors::renderEndUserTemplate() — os tipos
        // permitidos pela pergunta (Requerente/Grupo/Fornecedor conforme o
        // subtipo) restritos aos habilitados na config dessa pergunta
        // específica (extra_data.enabled_types).
        $allowedTypes = array_values(array_filter(
            $questionType->getAllowedActorTypes(),
            fn (string $type) => $questionType->isTypeEnabled($question, $type)
        ));

        $searchText = (string) $request->query->get('q', '');
        $page = max(1, (int) $request->query->get('page', 1));
        $pageSize = (int) $request->query->get('pageSize', 20);
        if ($pageSize <= 0) {
            $pageSize = 20;
        }

        $raw = FormActorsDropdown::fetchValues($searchText, [
            'allowed_types'    => $allowedTypes,
            'right_for_users'  => $questionType->getRightForUsers(),
            'group_conditions' => $questionType->getGroupConditions(),
            'page'             => $page,
            'page_size'        => $pageSize,
        ]);

        return new JsonResponse($this->formatResult($raw));
    }

    /** Select2 agrupado do core -> {groups: [{itemtype, label, options: [{id, itemtype, label, subtitle}]}]} */
    private function formatResult(array $raw): array
    {
        $groups = [];
        foreach ($raw['results'] ?? [] as $group) {
            $groups[] = [
                'itemtype' => $group['itemtype'] ?? '',
                'label' => $group['text'] ?? '',
                'options' => array_map(
                    fn (array $child) => [
                        'id' => $child['id'],
                        'itemtype' => $child['itemtype'] ?? '',
                        'label' => $child['text'] ?? '',
                        'subtitle' => $child['title'] ?? null,
                    ],
                    $group['children'] ?? []
                ),
            ];
        }

        return ['groups' => $groups, 'count' => (int) ($raw['count'] ?? 0)];
    }
}
