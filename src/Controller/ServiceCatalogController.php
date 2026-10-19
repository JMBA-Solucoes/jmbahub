<?php

/**
 * Lista os itens do catálogo de serviços (categorias, formulários e
 * artigos da base de conhecimento) num JSON enxuto pro app navegar
 * nativamente — estende a renderização nativa dos formulários (que já
 * cobre o formulário em si) pra também cobrir a navegação até ele.
 *
 * O GLPI continua sendo a única fonte de verdade: este endpoint só chama
 * `Glpi\Form\ServiceCatalog\ServiceCatalogManager`, a mesma classe que o
 * controller web nativo do GLPI (`Glpi\Controller\ServiceCatalog\ItemsController`)
 * usa por trás — controle de acesso, entidade, paginação e busca (fuzzy
 * match) vêm de lá, sem reimplementar nada. A diferença é só o formato de
 * saída (JSON em vez de HTML/Twig).
 *
 * URL: GET {glpiUrl}/plugins/jmbahub/ServiceCatalog
 *        ?categoryId=&page=&search=&glpiUserId=&activeEntityId=&activeEntityRecursive=&activeProfileId=
 * Header: X-GLPI-App-Secret (mesmo secret de checkuser.php/FormStructure)
 */

namespace GlpiPlugin\Jmbahub\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Form\AccessControl\FormAccessParameters;
use Glpi\Form\Category;
use Glpi\Form\Form;
use Glpi\Form\ServiceCatalog\ItemRequest;
use Glpi\Form\ServiceCatalog\Provider\CategoryProvider;
use Glpi\Form\ServiceCatalog\ServiceCatalogItemInterface;
use Glpi\Form\ServiceCatalog\ServiceCatalogLeafInterface;
use Glpi\Form\ServiceCatalog\ServiceCatalogManager;
use Glpi\Http\Firewall;
use Glpi\RichText\RichText;
use Glpi\Security\Attribute\SecurityStrategy;
use Glpi\UI\IllustrationManager;
use KnowbaseItem;
use Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ServiceCatalogController extends AbstractController
{
    use NativeFormsAuthTrait;

    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)] // sem sessão prévia — este controller cria uma
    #[Route(
        "/ServiceCatalog",
        name: "jmbahub_service_catalog",
        methods: ['GET'],
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

        // 0 = raiz (igual o controller web nativo do GLPI, Glpi\Controller\
        // ServiceCatalog\ItemsController — usa 0, não null). É um valor
        // significativo pros providers: FormProvider/CategoryProvider tratam
        // `category_id !== null` como "restringe por forms_categories_id",
        // então raiz com `0` restringe a formulários/categorias sem pai (o
        // esperado); só a busca (abaixo) usa `null` de propósito, pra remover
        // a restrição e procurar em todas as categorias de uma vez.
        $categoryId = 0;
        if ($request->query->has('categoryId') && $request->query->get('categoryId') !== '') {
            $categoryId = (int) $request->query->get('categoryId');
            if ($categoryId > 0 && Category::getById($categoryId) === false) {
                return new JsonResponse(['error' => 'Categoria não encontrada.'], 404);
            }
        }

        $filter = (string) $request->query->get('search', '');
        $page = max(1, (int) $request->query->get('page', 1));

        // Igual o core faz: com busca preenchida, procura em todas as
        // categorias (sem restrição) em vez de restringir à categoria atual.
        if ($filter !== '') {
            $categoryId = null;
        }

        $parameters = new FormAccessParameters(
            session_info: Session::getCurrentSessionInfo(),
        );

        $itemRequest = new ItemRequest(
            access_parameters: $parameters,
            filter: $filter,
            category_id: $categoryId,
            page: $page,
            items_per_page: ServiceCatalogManager::ITEMS_PER_PAGE,
        );

        $result = ServiceCatalogManager::getInstance()->getItems($itemRequest);

        $ancestors = ($categoryId !== null && $categoryId > 0)
            ? (new CategoryProvider())->getAncestors($itemRequest)
            : [];

        return new JsonResponse([
            'items'         => array_values(array_map(
                fn (ServiceCatalogItemInterface $item) => $this->serializeItem($item),
                $result['items']
            )),
            'total'         => $result['total'],
            'page'          => $page,
            'itemsPerPage'  => ServiceCatalogManager::ITEMS_PER_PAGE,
            'ancestors'     => $ancestors,
        ]);
    }

    private function serializeItem(ServiceCatalogItemInterface $item): array
    {
        $type = match (true) {
            $item instanceof Form => 'form',
            $item instanceof Category => 'category',
            $item instanceof KnowbaseItem => 'knowbaseitem',
            default => 'unknown',
        };

        // A descrição vem em HTML (rich text) — a listagem do app é texto
        // puro, sem renderizador de HTML, então converte aqui uma vez só
        // (keep_presentation=false, compact=true: sem <p>/links, só o texto).
        $rawDescription = $item->getServiceCatalogItemDescription();
        $description = $rawDescription !== ''
            ? trim(RichText::getTextFromHtml($rawDescription, false, true))
            : '';

        $illustration = $item->getServiceCatalogItemIllustration();

        return [
            'id'            => method_exists($item, 'getID') ? (int) $item->getID() : null,
            'type'          => $type,
            'title'         => $item->getServiceCatalogItemTitle(),
            'description'   => $description,
            'illustration'  => $illustration,
            'illustrationDataUri' => $this->getCustomIllustrationDataUri($illustration),
            'isPinned'      => $item->isServiceCatalogItemPinned(),
            'isLeaf'        => $item instanceof ServiceCatalogLeafInterface,
        ];
    }

    /**
     * Entrega ao app somente ilustrações personalizadas já autorizadas pelo
     * catálogo. O data URI evita expor o arquivo interno do GLPI e dispensa
     * uma segunda rota/sessão para baixar a imagem.
     */
    private function getCustomIllustrationDataUri(string $illustration): ?string
    {
        if (!str_starts_with($illustration, IllustrationManager::CUSTOM_ILLUSTRATION_PREFIX)) {
            return null;
        }

        $manager = new IllustrationManager();
        $id = $manager->getCustomIconIdFromPrefixedString($illustration);
        $path = $manager->getCustomIllustrationFile($id);
        if ($path === null || !is_readable($path)) {
            return null;
        }

        // Evita que um upload muito grande infle a resposta paginada.
        $size = filesize($path);
        if ($size === false || $size > 2 * 1024 * 1024) {
            return null;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        return sprintf('data:%s;base64,%s', $mime, base64_encode($contents));
    }
}
