<?php
/* For licensing terms, see /license.txt */

namespace Chamilo\CoreBundle\Controller;

use APY\DataGridBundle\Grid\Action\MassAction;
use APY\DataGridBundle\Grid\Action\RowAction;
use APY\DataGridBundle\Grid\Export\CSVExport;
use APY\DataGridBundle\Grid\Export\ExcelExport;
use APY\DataGridBundle\Grid\Grid;
use APY\DataGridBundle\Grid\Source\Entity;
use Chamilo\CoreBundle\Component\Utils\Glide;
use Chamilo\CoreBundle\Entity\Resource\ResourceNode;
use Chamilo\CoreBundle\Entity\Resource\ResourceRight;
use Chamilo\CoreBundle\Security\Authorization\Voter\ResourceNodeVoter;
use Chamilo\CourseBundle\Controller\CourseControllerInterface;
use Chamilo\CourseBundle\Controller\CourseControllerTrait;
use Chamilo\CourseBundle\Entity\CDocument;
use Chamilo\CourseBundle\Repository\CDocumentRepository;
use FOS\RestBundle\View\View;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Resource\Exception\UpdateHandlingException;
use Sylius\Component\Resource\ResourceActions;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Vich\UploaderBundle\Util\Transliterator;

/**
 * Class ResourceController.
 *
 * @author Julio Montoya <gugli100@gmail.com>.
 */
class ResourceController extends BaseController implements CourseControllerInterface
{
    use CourseControllerTrait;

    public function indexAction(Request $request): Response
    {
        return [];

        $source = new Entity('ChamiloCourseBundle:CDocument');

        /* @var Grid $grid */
        $grid = $this->get('grid');

        /*$tableAlias = $source->getTableAlias();
        $source->manipulateQuery(function (QueryBuilder $query) use ($tableAlias, $course) {
                $query->andWhere($tableAlias . '.cId = '.$course->getId());
                //$query->resetDQLPart('orderBy');
            }
        );*/

        $repository = $this->get('Chamilo\CourseBundle\Repository\CDocumentRepository');

        $course = $this->getCourse();
        $tool = $repository->getTool('document');

        $parentId = $request->get('parent');
        $parent = null;
        if (!empty($parentId)) {
            $parent = $repository->find($parentId);
        }
        $resources = $repository->getResourcesByCourse($course, $tool, $parent);

        $source->setData($resources);
        $grid->setSource($source);

        //$grid->hideFilters();
        $grid->setLimits(20);
        //$grid->isReadyForRedirect();
        //$grid->setMaxResults(1);
        //$grid->setLimits(2);
        /*$grid->getColumn('id')->manipulateRenderCell(
            function ($value, $row, $router) use ($course) {
                //$router = $this->get('router');
                return $router->generate(
                    'chamilo_notebook_show',
                    array('id' => $row->getField('id'), 'course' => $course)
                );
            }
        );*/

        $courseIdentifier = $course->getCode();

        if ($this->isGranted(ResourceNodeVoter::ROLE_CURRENT_COURSE_TEACHER)) {
            $deleteMassAction = new MassAction(
                'Delete',
                'chamilo.controller.notebook:deleteMassAction',
                true,
                ['course' => $courseIdentifier]
            );
            $grid->addMassAction($deleteMassAction);
        }

        $translation = $this->container->get('translator');

        $myRowAction = new RowAction(
            $translation->trans('View'),
            'app_document_show',
            false,
            '_self',
            ['class' => 'btn btn-secondary']
        );
        $myRowAction->setRouteParameters(['course' => $courseIdentifier, 'id']);
        $grid->addRowAction($myRowAction);

        if ($this->isGranted(ResourceNodeVoter::ROLE_CURRENT_COURSE_TEACHER)) {
            $myRowAction = new RowAction(
                $translation->trans('Edit'),
                'app_document_update',
                false,
                '_self',
                ['class' => 'btn btn-secondary']
            );
            $myRowAction->setRouteParameters(['course' => $courseIdentifier, 'id']);
            $grid->addRowAction($myRowAction);

            $myRowAction = new RowAction(
                $translation->trans('Delete'),
                'app_document_delete',
                false,
                '_self',
                ['class' => 'btn btn-danger', 'form_delete' => true]
            );
            $myRowAction->setRouteParameters(['course' => $courseIdentifier, 'id']);
            $grid->addRowAction($myRowAction);
        }

        $grid->addExport(new CSVExport($translation->trans('CSV Export'), 'export', ['course' => $courseIdentifier]));

        $grid->addExport(
            new ExcelExport(
                $translation->trans('Excel Export'),
                'export',
                ['course' => $courseIdentifier]
            )
        );

        return $grid->getGridResponse('ChamiloCoreBundle:Document:index.html.twig', ['parent_id' => $parentId]);
    }

    public function listAction(Request $request, $type): Response
    {
        $source = new Entity('ChamiloCourseBundle:CDocument');

        /* @var Grid $grid */
        $grid = $this->get('grid');

        return $grid->getGridResponse('@ChamiloCore/Resource/index.html.twig', ['grid' => $grid]);
    }

    /**
     * @param string $fileType
     *
     * @return RedirectResponse|Response|null
     */
    public function createResource(Request $request, $fileType = 'file')
    {
        $configuration = $this->requestConfigurationFactory->create($this->metadata, $request);

        $this->isGrantedOr403($configuration, ResourceActions::CREATE);
        /** @var CDocument $newResource */
        $newResource = $this->newResourceFactory->create($configuration, $this->factory);
        $form = $this->resourceFormFactory->create($configuration, $newResource);

        $course = $this->getCourse();
        $session = $this->getSession();
        $newResource->setCourse($course);
        $newResource->c_id = $course->getId();
        $newResource->setFiletype($fileType);
        $form->setData($newResource);

        $parentId = $request->get('parent');
        $parent = null;
        if (!empty($parentId)) {
            /** @var CDocument $parent */
            $parent = $this->repository->find($parentId);
        }

        if ($request->isMethod('POST') && $form->handleRequest($request)->isValid()) {
            /** @var CDocument $newResource */
            $newResource = $form->getData();
            $event = $this->eventDispatcher->dispatchPreEvent(ResourceActions::CREATE, $configuration, $newResource);

            if ($event->isStopped() && !$configuration->isHtmlRequest()) {
                throw new HttpException($event->getErrorCode(), $event->getMessage());
            }
            if ($event->isStopped()) {
                $this->flashHelper->addFlashFromEvent($configuration, $event);

                if ($event->hasResponse()) {
                    return $event->getResponse();
                }

                return $this->redirectHandler->redirectToIndex($configuration, $newResource);
            }

            if ($configuration->hasStateMachine()) {
                $this->stateMachine->apply($configuration, $newResource);
            }

            //$sharedType = $form->get('shared')->getData();
            $shareList = [];
            $sharedType = 'this_course';

            switch ($sharedType) {
                case 'this_course':
                    if (empty($course)) {
                        break;
                    }
                    // Default Chamilo behaviour:
                    // Teachers can edit and students can see
                    $shareList = [
                        [
                            'sharing' => 'course',
                            'mask' => ResourceNodeVoter::getReaderMask(),
                            'role' => ResourceNodeVoter::ROLE_CURRENT_COURSE_STUDENT,
                            'search' => $course->getId(),
                        ],
                        [
                            'sharing' => 'course',
                            'mask' => ResourceNodeVoter::getEditorMask(),
                            'role' => ResourceNodeVoter::ROLE_CURRENT_COURSE_TEACHER,
                            'search' => $course->getId(),
                        ],
                    ];
                    break;
                case 'shared':
                    $shareList = $form->get('rights')->getData();
                    break;
                case 'only_me':
                    $shareList = [
                        [
                            'sharing' => 'user',
                            'only_me' => true,
                        ],
                    ];
                    break;
            }

            $resourceNode = $repository->addResourceNode($newResource, $this->getUser(), $parent);

            // Loops all sharing options
            foreach ($shareList as $share) {
                $idList = [];
                if (isset($share['search'])) {
                    $idList = explode(',', $share['search']);
                }

                $resourceRight = null;
                if (isset($share['mask'])) {
                    $resourceRight = new ResourceRight();
                    $resourceRight
                        ->setMask($share['mask'])
                        ->setRole($share['role'])
                    ;
                }

                // Build links
                switch ($share['sharing']) {
                    case 'everyone':
                        $repository->addResourceToEveryone(
                            $resourceNode,
                            $resourceRight
                        );
                        break;
                    case 'course':
                        $repository->addResourceToCourse(
                            $resourceNode,
                            $course,
                            $resourceRight
                        );
                        break;
                    case 'session':
                        $repository->addResourceToSession(
                            $resourceNode,
                            $course,
                            $session,
                            $resourceRight
                        );
                        break;
                    case 'user':
                        // Only for me
                        if (isset($share['only_me'])) {
                            $repository->addResourceOnlyToMe($resourceNode);
                        } else {
                            // To other users
                            $repository->addResourceToUserList($resourceNode, $idList);
                        }
                        break;
                    case 'group':
                        // @todo
                        break;
                }
            }

            $newResource
                ->setCourse($course)
                ->setFiletype($fileType)
                ->setSession($session)
                //->setTitle($title)
                //->setComment($comment)
                ->setReadonly(false)
                ->setResourceNode($resourceNode)
            ;

            $path = \URLify::filter($newResource->getTitle());

            switch ($fileType) {
                case 'folder':
                    $newResource
                        ->setPath($path)
                        ->setSize(0)
                    ;
                    break;
                case 'file':
                    $newResource
                        ->setPath($path)
                        ->setSize(0)
                    ;
                    break;
            }

            $this->repository->add($newResource);
            $postEvent = $this->eventDispatcher->dispatchPostEvent(ResourceActions::CREATE, $configuration, $newResource);

            $newResource->setId($newResource->getIid());
            $this->getDoctrine()->getManager()->persist($newResource);
            $this->getDoctrine()->getManager()->flush();

            if (!$configuration->isHtmlRequest()) {
                return $this->viewHandler->handle($configuration, View::create($newResource, Response::HTTP_CREATED));
            }

            $this->addFlash('success', 'saved');

            //$this->flashHelper->addSuccessFlash($configuration, ResourceActions::CREATE, $newResource);
            if ($postEvent->hasResponse()) {
                return $postEvent->getResponse();
            }

            return $this->redirectToRoute(
                'app_document_show',
                [
                    'id' => $newResource->getIid(),
                    'course' => $course->getCode(),
                    'parent_id' => $parentId,
                ]
            );
            //return $this->redirectHandler->redirectToResource($configuration, $newResource);
        }

        if (!$configuration->isHtmlRequest()) {
            return $this->viewHandler->handle($configuration, View::create($form, Response::HTTP_BAD_REQUEST));
        }

        $initializeEvent = $this->eventDispatcher->dispatchInitializeEvent(ResourceActions::CREATE, $configuration, $newResource);
        if ($initializeEvent->hasResponse()) {
            return $initializeEvent->getResponse();
        }

        $view = View::create()
            ->setData([
                'configuration' => $configuration,
                'metadata' => $this->metadata,
                'resource' => $newResource,
                $this->metadata->getName() => $newResource,
                'form' => $form->createView(),
                'parent_id' => $parentId,
                'file_type' => $fileType,
            ])
            ->setTemplate($configuration->getTemplate(ResourceActions::CREATE.'.html'))
        ;

        return $this->viewHandler->handle($configuration, $view);
    }

    public function createAction(Request $request): Response
    {
        return $this->createResource($request, 'folder');
    }

    public function createDocumentAction(Request $request): Response
    {
        return $this->createResource($request, 'file');
    }

    /**
     * Shows a resource.
     */
    public function getResourceFileAction(Request $request, Glide $glide): Response
    {
        $id = $request->get('id');
        $filter = $request->get('filter');
        $em = $this->getDoctrine();
        $resourceNode = $em->getRepository('ChamiloCoreBundle:Resource\ResourceNode')->find($id);

        if ($resourceNode === null) {
            throw new FileNotFoundException('Not found');
        }

        return $this->showFile($request, $resourceNode, $glide, 'show', $filter);
    }

    /**
     * Shows a resource.
     *
     * @param CDocumentRepository $documentRepo
     * @param Glide               $glide
     */
    public function showAction(Request $request): Response
    {
        $em = $this->getDoctrine();

        $id = $request->get('id');
        $resourceNode = $em->getRepository('ChamiloCoreBundle:Resource\ResourceNode')->find($id);

        if (null === $resourceNode) {
            throw new NotFoundHttpException();
        }

        $this->denyAccessUnlessGranted(
            ResourceNodeVoter::VIEW,
            $resourceNode,
            'Unauthorised access to resource'
        );

        $params = [
            'resource_node' => $resourceNode,
        ];

        return $this->render('@ChamiloCore/Resource/info.html.twig', $params);
    }

    /**
     * @throws \League\Flysystem\FileNotFoundException
     */
    public function getDocumentAction(Request $request, CDocumentRepository $documentRepo, Glide $glide): Response
    {
        $file = $request->get('file');
        $type = $request->get('type');
        // see list of filters in config/services.yaml
        $filter = $request->get('filter');
        $type = !empty($type) ? $type : 'show';
        $criteria = [
            'path' => "/$file",
            'course' => $this->getCourse(),
        ];

        $document = $documentRepo->findOneBy($criteria);

        if (null === $document) {
            throw new NotFoundHttpException();
        }
        /** @var ResourceNode $resourceNode */
        $resourceNode = $document->getResourceNode();

        return $this->showFile($request, $resourceNode, $glide, $type, $filter);
    }

    public function updateAction(Request $request): Response
    {
        $configuration = $this->requestConfigurationFactory->create($this->metadata, $request);

        $this->isGrantedOr403($configuration, ResourceActions::UPDATE);
        /** @var CDocument $resource */
        $resource = $this->findOr404($configuration);
        $resourceNode = $resource->getResourceNode();

        $this->denyAccessUnlessGranted(
            ResourceNodeVoter::EDIT,
            $resourceNode,
            'Unauthorised access to resource'
        );

        $form = $this->resourceFormFactory->create($configuration, $resource);

        if (in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'], true) && $form->handleRequest($request)->isValid()) {
            $resource = $form->getData();

            /** @var ResourceControllerEvent $event */
            $event = $this->eventDispatcher->dispatchPreEvent(ResourceActions::UPDATE, $configuration, $resource);

            if ($event->isStopped() && !$configuration->isHtmlRequest()) {
                throw new HttpException($event->getErrorCode(), $event->getMessage());
            }
            if ($event->isStopped()) {
                $this->flashHelper->addFlashFromEvent($configuration, $event);

                if ($event->hasResponse()) {
                    return $event->getResponse();
                }

                return $this->redirectHandler->redirectToResource($configuration, $resource);
            }

            try {
                $this->resourceUpdateHandler->handle($resource, $configuration, $this->manager);
            } catch (UpdateHandlingException $exception) {
                if (!$configuration->isHtmlRequest()) {
                    return $this->viewHandler->handle(
                        $configuration,
                        View::create($form, $exception->getApiResponseCode())
                    );
                }

                $this->flashHelper->addErrorFlash($configuration, $exception->getFlash());

                return $this->redirectHandler->redirectToReferer($configuration);
            }

            $postEvent = $this->eventDispatcher->dispatchPostEvent(ResourceActions::UPDATE, $configuration, $resource);

            if (!$configuration->isHtmlRequest()) {
                $view = $configuration->getParameters()->get('return_content', false) ? View::create($resource, Response::HTTP_OK) : View::create(null, Response::HTTP_NO_CONTENT);

                return $this->viewHandler->handle($configuration, $view);
            }

            $this->flashHelper->addSuccessFlash($configuration, ResourceActions::UPDATE, $resource);

            if ($postEvent->hasResponse()) {
                return $postEvent->getResponse();
            }

            return $this->redirectHandler->redirectToResource($configuration, $resource);
        }

        if (!$configuration->isHtmlRequest()) {
            return $this->viewHandler->handle($configuration, View::create($form, Response::HTTP_BAD_REQUEST));
        }

        $initializeEvent = $this->eventDispatcher->dispatchInitializeEvent(ResourceActions::UPDATE, $configuration, $resource);
        if ($initializeEvent->hasResponse()) {
            return $initializeEvent->getResponse();
        }

        $view = View::create()
            ->setData([
                'configuration' => $configuration,
                'metadata' => $this->metadata,
                'resource' => $resource,
                $this->metadata->getName() => $resource,
                'form' => $form->createView(),
            ])
            ->setTemplate($configuration->getTemplate(ResourceActions::UPDATE.'.html'))
        ;

        return $this->viewHandler->handle($configuration, $view);
    }

    /**
     * Upload form.
     *
     * @Route("/upload/{type}/{id}", name="resource_upload", methods={"GET", "POST"}, options={"expose"=true})
     */
    public function showUploadFormAction($type, $id): Response
    {
        //$helper = $this->container->get('oneup_uploader.templating.uploader_helper');
        //$endpoint = $helper->endpoint('courses');
        return $this->render(
            '@ChamiloCore/Resource/upload.html.twig',
            [
                'identifier' => $id,
                'type' => $type,
            ]
        );
    }

    /**
     * @param        $type
     * @param string $filter
     *
     * @throws \League\Flysystem\FileNotFoundException
     *
     * @return mixed|StreamedResponse
     */
    private function showFile(Request $request, ResourceNode $resourceNode, Glide $glide, $type, $filter = '')
    {
        $fs = $this->container->get('oneup_flysystem.resources_filesystem');

        $this->denyAccessUnlessGranted(
            ResourceNodeVoter::VIEW,
            $resourceNode,
            'Unauthorised access to resource'
        );
        $resourceFile = $resourceNode->getResourceFile();

        if (!$resourceFile) {
            throw new NotFoundHttpException();
        }

        $fileName = $resourceNode->getName();
        $filePath = $resourceFile->getFile()->getPathname();
        $mimeType = $resourceFile->getMimeType();

        switch ($type) {
            case 'download':
                $forceDownload = true;
                break;
            case 'show':
            default:
                $forceDownload = false;
                if (strpos($mimeType, 'image') !== false) {
                    $server = $glide->getServer();
                    $params = $request->query->all();

                    // The filter overwrites the params from get
                    if (!empty($filter)) {
                        $params = $glide->getFilters()[$filter] ?? [];
                    }

                    // The image was cropped manually by the user, so we force to render this version,
                    // no matter other crop parameters.
                    $crop = $resourceFile->getCrop();
                    if (!empty($crop)) {
                        $params['crop'] = $crop;
                    }

                    return $server->getImageResponse($filePath, $params);
                }
                break;
        }

        $stream = $fs->readStream($filePath);
        $response = new StreamedResponse(function () use ($stream): void {
            stream_copy_to_stream($stream, fopen('php://output', 'wb'));
        });
        $disposition = $response->headers->makeDisposition(
            $forceDownload ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE,
            Transliterator::transliterate($fileName)
        );
        $response->headers->set('Content-Disposition', $disposition);
        $response->headers->set('Content-Type', $mimeType ?: 'application/octet-stream');

        return $response;
    }
}
