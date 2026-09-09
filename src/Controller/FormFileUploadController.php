<?php

/**
 * Staging de arquivo pra uma pergunta do tipo File — Entrega C5 da
 * renderização nativa dos formulários. Era o item mais arriscado do plano
 * original (upload nativo do GLPI em 2 chamadas, a 1ª supostamente exigindo
 * sessão de navegador que o app não tem), mas investigando o código-fonte
 * (`GLPIUploadHandler`/`UploadHandler`) o staging não depende de sessão
 * nenhuma — só grava o arquivo em `GLPI_TMP_DIR` com um nome único.
 *
 * **Achado real de teste (27/08/2026)**: sem prefixo nenhum no nome do
 * arquivo, 2 uploads consecutivos com o mesmo nome colidem em
 * `GLPI_TMP_DIR` e acionam `UploadHandler::upcount_name()` pra desambiguar
 * — método que tem um bug de verdade nesta versão (GLPI 11.0.5 +
 * `thecodingmachine/safe` instalado): `Safe\preg_replace_callback()` rejeita
 * o callback em formato array (`[$this, 'upcount_name_callback']`) que o
 * método passa, com `TypeError`. O upload nativo do GLPI nunca aciona esse
 * caminho porque o JS do core sempre prefixa o nome do arquivo com uma
 * string aleatória antes de enviar — replicamos exatamente essa prevenção
 * (prefixo aleatório de comprimento fixo), tornando colisão praticamente
 * impossível, em vez de tentar corrigir/contornar o bug em si.
 *
 * O prefixo tem 23 caracteres pra bater com o que
 * `GLPIUploadHandler::uploadFiles()` assume internamente
 * (`substr($name, 0, 23)`) — não que a gente dependa dessa conta pra nada
 * (calculamos o nome de exibição nós mesmos, ver abaixo), só não faz mal
 * manter os dois iguais.
 *
 * Não reimplementa a lógica de staging em si: delega pra
 * `GLPIUploadHandler::uploadFiles()`, a mesma classe usada por
 * `ajax/fileupload.php` — validação de tamanho (`document_max_size`) e de
 * extensão (`DocumentType::getUploadableFilePattern()`) já vêm do core de
 * graça.
 *
 * URL: POST {glpiUrl}/plugins/jmbahub/FormFileUpload/{formId}/{questionId}
 *        ?glpiUserId=&activeEntityId=&activeEntityRecursive=&activeProfileId=
 * Body: multipart/form-data, 1 arquivo por chamada, sob a chave "file".
 * Header: X-GLPI-App-Secret (mesmo secret de checkuser.php/Consume/FormStructure/FormSubmit/FormActors/FormItems)
 *
 * Resposta 200: { "name": "a1b2...-relatorio.pdf", "displayName": "relatorio.pdf", "size": 12345 }
 * Resposta 422: { "error": "..." } (arquivo recusado por tamanho/tipo)
 *
 * `name` é o que vai no array de resposta da pergunta File no submit (ver
 * FormSubmitController); `displayName` é só informativo pro app mostrar
 * enquanto o usuário preenche o formulário.
 */

namespace GlpiPlugin\Jmbahub\Controller;

use GLPIUploadHandler;
use Glpi\Controller\AbstractController;
use Glpi\Form\Form;
use Glpi\Form\Question;
use Glpi\Form\QuestionType\QuestionTypeFile;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FormFileUploadController extends AbstractController
{
    use NativeFormsAuthTrait;

    /** Ver docblock da classe — mesmo tamanho assumido por GLPIUploadHandler::uploadFiles(). */
    public const PREFIX_LENGTH = 23;

    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)] // sem sessão prévia — este controller cria uma
    #[Route(
        "/FormFileUpload/{formId}/{questionId}",
        name: "jmbahub_form_file_upload",
        methods: ['GET', 'POST'], // gotcha do GLPI 11.0.5 — ver docblock de FormSubmitController
        requirements: ['formId' => '\d+', 'questionId' => '\d+'],
    )]
    public function __invoke(Request $request): Response
    {
        if ($request->getMethod() !== 'POST') {
            return new JsonResponse(['error' => 'Método não permitido.'], 405);
        }

        // Autenticação vem da query string (não do corpo multipart) — mesmo
        // padrão de FormActorsSearchController/FormItemSearchController,
        // pra manter a chamada do backend Java simples (URL com os
        // parâmetros de contexto + corpo só com o arquivo).
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

        if (!$question->getQuestionType() instanceof QuestionTypeFile) {
            return new JsonResponse(['error' => 'Pergunta não é do tipo Arquivo.'], 400);
        }

        if (!isset($_FILES['file'])) {
            return new JsonResponse(['error' => 'Nenhum arquivo enviado.'], 400);
        }

        // Ver docblock da classe: prefixamos o nome recebido com uma string
        // aleatória de comprimento fixo pra evitar colisão em GLPI_TMP_DIR
        // (e, com isso, o bug real de upcount_name() nesta versão do GLPI).
        // O nome de exibição é o original, guardado antes de mexer em $_FILES.
        $originalName = $_FILES['file']['name'] ?? 'arquivo';
        $prefix = substr(bin2hex(random_bytes(16)), 0, self::PREFIX_LENGTH);
        $_FILES['file']['name'] = $prefix . $originalName;

        // Convenção própria (não é a do core): sempre 1 arquivo por chamada,
        // sob a chave "file" em $_FILES/$_POST['name']. GLPIUploadHandler só
        // olha pra esses 2, não pro Request do Symfony.
        $response = GLPIUploadHandler::uploadFiles([
            'name'           => 'file',
            'print_response' => false,
        ]);

        $files = is_array($response['file'] ?? null) ? $response['file'] : [];
        $file = $files[0] ?? null;
        if (!is_object($file) || isset($file->error)) {
            $message = is_object($file) ? ($file->error ?? null) : null;
            return new JsonResponse(['error' => $message ?? 'Falha ao processar o arquivo.'], 422);
        }

        return new JsonResponse([
            'name' => $file->name,
            'displayName' => $originalName,
            'size' => (int) ($file->size ?? 0),
        ]);
    }
}
