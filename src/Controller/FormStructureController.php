<?php

/**
 * Devolve a estrutura de um formulário nativo do catálogo (seções +
 * perguntas) num JSON enxuto pro app renderizar campos nativos — Entrega A
 * da renderização nativa dos formulários.
 *
 * O GLPI continua sendo a única fonte de verdade: este endpoint só expõe o
 * que já existe em Question/Section, sem reimplementar nada. Validação e
 * gravação seguem em FormSubmitController, via AnswersHandler.
 *
 * URL: GET {glpiUrl}/plugins/jmbahub/FormStructure/{id}
 *        ?glpiUserId=&activeEntityId=&activeEntityRecursive=&activeProfileId=
 * Header: X-GLPI-App-Secret (mesmo secret de checkuser.php/Consume)
 */

namespace GlpiPlugin\Jmbahub\Controller;

use CommonITILObject;
use CommonItilObject_Item;
use Config;
use Glpi\Controller\AbstractController;
use Glpi\Form\Form;
use Glpi\Form\Comment;
use Glpi\Form\Question;
use Glpi\Form\Section;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use Glpi\Urgency;
use ITILCategory;
use Session;
use Ticket;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FormStructureController extends AbstractController
{
    use NativeFormsAuthTrait;

    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)] // sem sessão prévia — este controller cria uma
    #[Route(
        "/FormStructure/{id}",
        name: "jmbahub_form_structure",
        methods: ['GET'],
        requirements: ['id' => '\d+'],
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

        $form = Form::getById((int) $request->get('id'));
        if (!$form instanceof Form) {
            return new JsonResponse(['error' => 'Formulário não encontrado.'], 404);
        }

        return new JsonResponse($this->serializeForm($form));
    }

    private function serializeForm(Form $form): array
    {
        $sections = array_map(
            fn (Section $section) => $this->serializeSection($section),
            $form->getSections()
        );
        usort($sections, fn ($a, $b) => $a['rank'] <=> $b['rank']);

        return [
            'id' => (int) $form->fields['id'],
            'name' => $form->fields['name'] ?? '',
            'description' => $form->fields['description'] ?? null,
            'sections' => array_values($sections),
        ];
    }

    private function serializeSection(Section $section): array
    {
        $questions = array_map(
            fn (Question $question) => $this->serializeQuestion($question),
            $section->getQuestions()
        );
        usort($questions, fn ($a, $b) => $a['rank'] <=> $b['rank']);
        $comments = array_map(
            fn (Comment $comment) => $this->serializeComment($comment),
            $section->getFormComments()
        );
        usort($comments, fn ($a, $b) => $a['rank'] <=> $b['rank']);

        $fields = $section->fields;
        $conditions = json_decode($fields['conditions'] ?? '[]', true) ?: [];

        return [
            'id' => (int) $fields['id'],
            'name' => $fields['name'] ?? '',
            'rank' => (int) ($fields['rank'] ?? 0),
            'visibilityStrategy' => $this->normalizeVisibilityStrategy($fields['visibility_strategy'] ?? null),
            'conditions' => $this->serializeConditions($conditions),
            'comments' => array_values($comments),
            'questions' => array_values($questions),
        ];
    }

    private function serializeComment(Comment $comment): array
    {
        $fields = $comment->fields;
        $conditions = json_decode($fields['conditions'] ?? '[]', true) ?: [];

        return [
            'id' => (int) $fields['id'],
            'name' => $fields['name'] ?? '',
            'description' => $fields['description'] ?? null,
            'rank' => (int) ($fields['vertical_rank'] ?? 0),
            'visibilityStrategy' => $this->normalizeVisibilityStrategy($fields['visibility_strategy'] ?? null),
            'conditions' => $this->serializeConditions($conditions),
        ];
    }

    private function serializeQuestion(Question $question): array
    {
        $fields = $question->fields;
        $conditions = json_decode($fields['conditions'] ?? '[]', true) ?: [];
        $validationConditions = json_decode($fields['validation_conditions'] ?? '[]', true) ?: [];
        $type = $this->shortenType($fields['type'] ?? '');
        $extraData = !empty($fields['extra_data']) ? json_decode($fields['extra_data'], true) : null;

        return [
            'id' => (int) $fields['id'],
            'name' => $fields['name'] ?? '',
            'type' => $type,
            'description' => $fields['description'] ?? null,
            'isMandatory' => (bool) ($fields['is_mandatory'] ?? false),
            'defaultValue' => $fields['default_value'] ?? null,
            'extraData' => $this->enrichExtraData($type, $extraData),
            'rank' => (int) ($fields['vertical_rank'] ?? 0),
            'visibilityStrategy' => $this->normalizeVisibilityStrategy($fields['visibility_strategy'] ?? null),
            'conditions' => $this->serializeConditions($conditions),
            // Mantidos por compatibilidade com o critério de fallback anterior
            // (Entregas B/C); `native-form-support.ts` passa a usar
            // `visibilityStrategy`/`conditions` a partir da Entrega D.
            'hasVisibilityConditions' => count($conditions) > 0,
            'hasValidationConditions' => count($validationConditions) > 0,
        ];
    }

    /**
     * Traduz as condições cruas do GLPI (`Glpi\Form\Condition\ConditionData`,
     * decodificadas direto de `glpi_forms_questions.conditions`/
     * `glpi_forms_sections.conditions`) pro formato que o app consegue usar
     * sem precisar entender UUID nenhum: resolve `item_uuid` pro id de
     * pergunta/seção correspondente (`Question`/`Section::getByUuid()`, os
     * mesmos lookups que `Glpi\Form\Condition\Engine::computeCondition()`
     * usa). Quando o alvo é um `comment` (bloco informativo que o app não
     * renderiza) ou o uuid não resolve, os dois ids saem `null` — sinal pro
     * app tratar a condição como não suportada e cair no fallback WebView
     * (ver `isFormNativelySupported`).
     *
     * @param array<int, array<string, mixed>> $rawConditions
     * @return array<int, array<string, mixed>>
     */
    private function serializeConditions(array $rawConditions): array
    {
        return array_values(array_map(
            fn (array $condition) => $this->serializeCondition($condition),
            $rawConditions
        ));
    }

    /**
     * `glpi_forms_questions.visibility_strategy`/`glpi_forms_sections.visibility_strategy`
     * vêm string vazia (`''`) no banco pra perguntas/seções nunca configuradas
     * com condição — não `NULL` — então `?? 'always_visible'` sozinho não
     * pega esse caso (achado em teste de campo contra o form real "Reportar
     * um problema" em 27/08/2026: perguntas sem condição alguma chegavam com
     * `visibilityStrategy: ""`). Trata `''` como `always_visible`, igual o
     * enum `VisibilityStrategy::ALWAYS_VISIBLE` faz no core.
     */
    private function normalizeVisibilityStrategy(?string $rawStrategy): string
    {
        return !empty($rawStrategy) ? $rawStrategy : 'always_visible';
    }

    private function serializeCondition(array $condition): array
    {
        $itemType = $condition['item_type'] ?? null;
        $itemUuid = $condition['item_uuid'] ?? null;

        $targetQuestionId = null;
        $targetSectionId = null;
        $targetCommentId = null;
        if ($itemType === 'question' && !empty($itemUuid)) {
            $target = Question::getByUuid($itemUuid);
            $targetQuestionId = $target instanceof Question ? (int) $target->fields['id'] : null;
        } elseif ($itemType === 'section' && !empty($itemUuid)) {
            $target = Section::getByUuid($itemUuid);
            $targetSectionId = $target instanceof Section ? (int) $target->fields['id'] : null;
        } elseif ($itemType === 'comment' && !empty($itemUuid)) {
            $target = Comment::getByUuid($itemUuid);
            $targetCommentId = $target instanceof Comment ? (int) $target->fields['id'] : null;
        }

        return [
            'itemType' => $itemType,
            'targetQuestionId' => $targetQuestionId,
            'targetSectionId' => $targetSectionId,
            'targetCommentId' => $targetCommentId,
            'valueOperator' => $condition['value_operator'] ?? null,
            'value' => $condition['value'] ?? null,
            'logicOperator' => $condition['logic_operator'] ?? null,
        ];
    }

    /**
     * Alguns tipos de pergunta não guardam as opções do dropdown em
     * `extra_data` — elas vêm de configuração global do GLPI
     * (`urgency_mask`, configurável por instância em Configurar > Geral >
     * Ativos). Sem isso o app teria que adivinhar ou hardcodar a escala, o
     * que quebraria em qualquer instância com níveis desabilitados.
     *
     * Não usa `Urgency::getEnabledUrgencyValuesForDropdown()` (que lê
     * `global $CFG_GLPI`) porque, em teste real (18/08/2026), esse global
     * veio vazio no bootstrap enxuto deste controller (`Session::init()`
     * direto, sem passar pelo front controller legado completo) — a
     * Urgência sempre chegava sem opção nenhuma no app. `Config::getConfigurationValues()`
     * lê direto de `glpi_configs`, então funciona independente desse
     * bootstrap. O GLPI segue sendo a única fonte de verdade: mesmo enum
     * (`Urgency::cases()`) e mesmos rótulos (`CommonITILObject::getUrgencyName()`)
     * do core, só a leitura da mask é que não depende mais do global.
     */
    private function enrichExtraData(string $type, ?array $extraData): ?array
    {
        // NexTool Form Extender persiste as categorias permitidas em
        // `extra_data.categories` (mapa id => id). No formulário HTML o
        // plugin transforma esse mapa em um select simples cujo valor é o
        // ID da ITILCategory. Expomos a mesma lista com os nomes completos
        // para o app não precisar consultar nem reproduzir o filtro do
        // Form Extender.
        if ($type === 'FormExtenderITILCategory') {
            $levels = [];
            $categoryIds = array_values((array) ($extraData['categories'] ?? []));
            foreach (array_unique(array_map('intval', $categoryIds)) as $categoryId) {
                if ($categoryId <= 0) {
                    continue;
                }
                $category = ITILCategory::getById($categoryId);
                if (!$category instanceof ITILCategory) {
                    continue;
                }
                $levels[] = [
                    'value' => $categoryId,
                    'label' => $category->fields['completename'] ?? $category->fields['name'] ?? (string) $categoryId,
                ];
            }
            return array_merge($extraData ?? [], ['levels' => $levels]);
        }

        // Algumas instalações antigas persistiram o flag do dropdown como
        // string ("0"/"1"). No JavaScript, "0" é truthy e fazia um dropdown
        // simples aparecer como múltipla escolha. O core converte esse valor
        // para bool ao criar QuestionTypeDropdownExtraDataConfig; fazemos a
        // mesma normalização antes de expor a estrutura ao app.
        if ($type === 'Dropdown') {
            return array_merge($extraData ?? [], [
                'is_multiple_dropdown' => filter_var(
                    $extraData['is_multiple_dropdown'] ?? false,
                    FILTER_VALIDATE_BOOLEAN
                ),
            ]);
        }

        if ($type === 'Urgency') {
            $config = Config::getConfigurationValues('core', ['urgency_mask']);
            $mask = (int) ($config['urgency_mask'] ?? 0);

            $levels = [];
            foreach (Urgency::cases() as $case) {
                if (($mask & (1 << $case->value)) > 0) {
                    $levels[] = ['value' => $case->value, 'label' => CommonITILObject::getUrgencyName($case->value)];
                }
            }
            return array_merge($extraData ?? [], ['levels' => $levels]);
        }

        // QuestionTypeRequestType não é o "Origem da requisição"
        // (glpi_requesttypes) — é o campo Incidente/Requisição do próprio
        // Ticket (Ticket::getTypes()), lista fixa de 2 opções, sem tabela.
        // `extra_data` desse tipo é sempre null no core; enriquecemos aqui
        // pro app não precisar hardcodar rótulos traduzidos.
        if ($type === 'RequestType') {
            $levels = [];
            foreach (Ticket::getTypes() as $value => $label) {
                $levels[] = ['value' => (int) $value, 'label' => $label];
            }
            return array_merge($extraData ?? [], ['levels' => $levels]);
        }

        // QuestionTypeUserDevice também não usa busca dinâmica: o próprio
        // core pré-carrega a lista inteira de "meus dispositivos" (ativos
        // vinculados ao usuário + aos grupos dele, respeitando entidade
        // ativa) no momento do render — mesma chamada exata usada por
        // QuestionTypeUserDevice::renderEndUserTemplate() no core, só que
        // aqui viram `options` no formato que o app já sabe desenhar
        // (mesmo shape de Checkbox/Radio/Dropdown), reaproveitando o
        // DropdownField pronto no app (single ou multi conforme
        // `is_multiple_devices`).
        if ($type === 'UserDevice') {
            $isMultiple = (bool) ($extraData['is_multiple_devices'] ?? false);
            $groups = CommonItilObject_Item::getMyDevices(Session::getLoginUserID(), Session::getActiveEntities());
            $options = [];
            foreach ($groups as $items) {
                foreach ($items as $key => $label) {
                    $options[$key] = $label;
                }
            }
            return ['options' => $options, 'is_multiple_dropdown' => $isMultiple];
        }

        return $extraData;
    }

    /** "Glpi\Form\QuestionType\QuestionTypeShortText" -> "ShortText" */
    private function shortenType(string $fqcn): string
    {
        // Tipos registrados pelo módulo NexTool Form Extender. Usamos nomes
        // estáveis no contrato JSON para não acoplar o app aos nomes globais
        // das classes PHP do plugin.
        if ($fqcn === 'PluginNextoolFormextenderQuestionType') {
            return 'FormExtenderITILCategory';
        }
        if ($fqcn === 'PluginNextoolFormextenderCpfCnpjQuestionType') {
            return 'FormExtenderCpfCnpj';
        }

        $short = strrchr($fqcn, '\\');
        $short = $short !== false ? substr($short, 1) : $fqcn;
        return str_starts_with($short, 'QuestionType') ? substr($short, strlen('QuestionType')) : $short;
    }
}
