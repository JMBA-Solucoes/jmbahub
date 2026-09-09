<?php

/**
 * Recebe as respostas coletadas nativamente no app e delega validação e
 * gravação pro próprio motor do GLPI — Entrega A da renderização nativa dos
 * formulários. O GLPI (AnswersHandler) continua sendo a única autoridade:
 * este controller não reimplementa nenhuma regra de obrigatoriedade,
 * condição ou destino.
 *
 * URL: POST {glpiUrl}/plugins/jmbahub/FormSubmit/{id}
 * Header: X-GLPI-App-Secret (mesmo secret de checkuser.php/Consume)
 * Body: {
 *   "glpiUserId": 2, "activeEntityId": 0, "activeEntityRecursive": true, "activeProfileId": 4,
 *   "answers": { "6": "Título", "7": "Descrição" }
 * }
 *
 * Resposta 200: { "valid": true, "created": [{"itemtype":"Ticket","id":123}] }
 * Resposta 422: { "valid": false, "errors": [{"questionId":7,"questionName":"...","message":"..."}] }
 *
 * Gotcha do GLPI 11.0.5 (ambiente de dev atual): `PluginsRouterListener`
 * não sincroniza o método HTTP real da requisição com o contexto do
 * roteador de rotas de plugin antes de casar a rota (fix presente só a
 * partir de uma versão posterior — comparado com o código-fonte de
 * referência 11.0.8 local) — o contexto avalia sempre como GET. Resultado:
 * uma rota de plugin restrita só a `methods: 'POST'` nunca casa e o GLPI
 * derruba a requisição com 500 (MethodNotAllowedException não tratada).
 * Contorno: declarar `methods: ['GET', 'POST']` — o *matching* passa a
 * aceitar (inclusive no ambiente com o bug, que sempre avalia GET), mas o
 * método/corpo reais continuam sendo POST de verdade pro Symfony\Request
 * (isso não passa pelo roteador com bug) — o controller processa a
 * requisição normalmente. Robusto também numa versão futura do GLPI onde
 * o bug já esteja corrigido, já que POST segue na lista.
 *
 * Entrega C5 (File): `QuestionTypeFile::prepareEndUserAnswer()` (chamado
 * por `AnswersHandler::createAnswserSet()` pra cada pergunta) cria o
 * `Document` certinho — nome, conteúdo, entidade — mas SEM
 * itemtype/items_id no input, porque nesse momento os itens de destino
 * (Ticket/Change/Problem) ainda não existem. Resultado: o Document nunca
 * fica linkado sozinho na aba Itens/Documentos do item criado.
 *
 * **Tentativa que NÃO deu certo** (documentada aqui pra não repetir):
 * `AnswersHandler::saveAnswers()` tem um 4º parâmetro `$files` — usado por
 * `AbstractCommonITILFormDestination::setFilesInput()`/
 * `CommonDBTM::addFiles()` do core pra fazer o Ticket tentar mover o MESMO
 * arquivo staged de novo, o que teoricamente linkaria o Document ao criar
 * o Ticket. Só que a essa altura o arquivo já foi movido pelo mecanismo
 * acima (não existe mais em GLPI_TMP_DIR) — `Document::getDuplicateOf()`
 * (via `sha1_file()` num caminho vazio) e o fallback de "arquivo não
 * encontrado" do core acabam linkando um documento ERRADO (achado real de
 * teste, 27/08/2026: o ticket criado ficou com o Document de um ticket
 * anterior, de outro teste).
 *
 * **Fix**: não usar o 4º parâmetro. Depois que `saveAnswers()` já criou os
 * itens de destino, linkamos manualmente os Documents que
 * `prepareEndUserAnswer()` já tinha criado corretamente — IDs recuperados
 * via `AnswersSet::getAnswersByType(QuestionTypeFile::class)` — usando
 * `Document_Item::add()` direto, a mesma operação padrão do GLPI pra
 * anexar um documento a um item.
 *
 * O prefixo de exibição de cada arquivo é sempre `substr($filename, 0, 23)`
 * — cada nome de arquivo enviado pelo app já vem com um prefixo aleatório de
 * 23 caracteres na frente (ver FormFileUploadController::PREFIX_LENGTH).
 */

namespace GlpiPlugin\Jmbahub\Controller;

use Document_Item;
use Glpi\Controller\AbstractController;
use Glpi\Form\AnswersHandler\AnswersHandler;
use Glpi\Form\AnswersSet;
use Glpi\Form\Form;
use Glpi\Form\Question;
use Glpi\Form\QuestionType\QuestionTypeFile;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FormSubmitController extends AbstractController
{
    use NativeFormsAuthTrait;

    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)] // sem sessão prévia — este controller cria uma
    #[Route(
        "/FormSubmit/{id}",
        name: "jmbahub_form_submit",
        methods: ['GET', 'POST'], // ver gotcha do GLPI 11.0.5 no docblock acima
        requirements: ['id' => '\d+'],
    )]
    public function __invoke(Request $request): Response
    {
        // A rota aceita GET no matching só por causa do gotcha do GLPI 11.0.5
        // (ver docblock da classe) — aqui, com o método real do Symfony\Request
        // (não afetado pelo bug), seguimos exigindo POST de verdade.
        if ($request->getMethod() !== 'POST') {
            return new JsonResponse(['error' => 'Método não permitido.'], 405);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Corpo da requisição inválido.'], 400);
        }

        $glpiUserId = (int) ($payload['glpiUserId'] ?? 0);
        $activeEntityId = isset($payload['activeEntityId']) ? (int) $payload['activeEntityId'] : null;
        $activeEntityRecursive = isset($payload['activeEntityRecursive']) ? (bool) $payload['activeEntityRecursive'] : null;
        $activeProfileId = isset($payload['activeProfileId']) ? (int) $payload['activeProfileId'] : null;

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

        $answers = [];
        foreach ((is_array($payload['answers'] ?? null) ? $payload['answers'] : []) as $questionId => $value) {
            $answers[(int) $questionId] = $value;
        }

        $this->populateFilePrefixes($form, $answers);

        $handler = AnswersHandler::getInstance();
        $validation = $handler->validateAnswers($form, $answers);

        if (!$validation->isValid()) {
            $errors = array_map(
                fn (array $error) => [
                    'questionId' => $error['question_id'] ?? null,
                    'questionName' => $error['question_name'] ?? null,
                    'message' => $error['message'] ?? 'Resposta inválida.',
                ],
                $validation->getErrors()
            );
            return new JsonResponse(['valid' => false, 'errors' => $errors], 422);
        }

        $answersSet = $handler->saveAnswers($form, $answers, $glpiUserId);
        $this->attachFilesToCreatedItems($answersSet);

        $created = array_map(
            fn ($item) => ['itemtype' => $item::class, 'id' => (int) $item->getID()],
            $answersSet->getCreatedItems()
        );

        return new JsonResponse(['valid' => true, 'created' => $created]);
    }

    /**
     * Ver docblock da classe (Entrega C5). Sem isso, o nome de exibição do
     * Document criado sai com o prefixo aleatório colado na frente (não é
     * um erro que a validação pega — a resposta continua `valid:true`, só
     * o nome do anexo no ticket sai errado).
     */
    private function populateFilePrefixes(Form $form, array $answers): void
    {
        $questions = $form->getQuestions();
        foreach ($answers as $questionId => $value) {
            $question = $questions[$questionId] ?? null;
            if (!$question instanceof Question || !$question->getQuestionType() instanceof QuestionTypeFile) {
                continue;
            }
            $filenames = is_array($value) ? $value : ($value !== null && $value !== '' ? [$value] : []);
            $_POST['_prefix_' . $question->getEndUserInputName()] = array_map(
                fn ($filename) => substr((string) $filename, 0, FormFileUploadController::PREFIX_LENGTH),
                $filenames
            );
        }
    }

    /**
     * Ver docblock da classe (Entrega C5). Linka na aba Itens/Documentos de
     * cada item criado pelo formulário os Documents que
     * `QuestionTypeFile::prepareEndUserAnswer()` já criou (a resposta de uma
     * pergunta File é o array de IDs do Document, não o nome do arquivo).
     */
    private function attachFilesToCreatedItems(AnswersSet $answersSet): void
    {
        $documentIds = [];
        foreach ($answersSet->getAnswersByType(QuestionTypeFile::class) as $answer) {
            foreach ((array) $answer->getRawAnswer() as $documentId) {
                $documentIds[] = (int) $documentId;
            }
        }
        if ($documentIds === []) {
            return;
        }

        foreach ($answersSet->getCreatedItems() as $item) {
            foreach ($documentIds as $documentId) {
                (new Document_Item())->add([
                    'documents_id' => $documentId,
                    'itemtype'     => $item::class,
                    'items_id'     => (int) $item->getID(),
                ]);
            }
        }
    }
}
