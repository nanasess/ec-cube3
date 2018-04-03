<?php

namespace Eccube\Controller\Admin\Shipping;

use Eccube\Common\Constant;
use Eccube\Controller\AbstractController;
use Eccube\Entity\MailHistory;
use Eccube\Entity\Master\ShippingStatus;
use Eccube\Entity\Shipping;
use Eccube\Event\EccubeEvents;
use Eccube\Event\EventArgs;
use Eccube\Form\Type\Admin\SearchShippingType;
use Eccube\Repository\Master\OrderStatusRepository;
use Eccube\Repository\Master\PageMaxRepository;
use Eccube\Repository\Master\ProductStatusRepository;
use Eccube\Repository\Master\ShippingStatusRepository;
use Eccube\Repository\ShippingRepository;
use Eccube\Service\MailService;
use Knp\Component\Pager\Paginator;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ShippingController extends AbstractController
{
    /**
     * @var OrderStatusRepository
     */
    protected $orderStatusRepository;

    /**
     * @var ShippingStatusRepository
     */
    protected $shippingStatusRepository;

    /**
     * @var ShippingRepository
     */
    protected $shippingRepository;

    /**
     * @var PageMaxRepository
     */
    protected $pageMaxRepository;

    /**
     * @var ProductStatusRepository
     */
    protected $productStatusRepository;
    /**
     * @var MailService
     */
    private $mailService;

    /**
     * ShippingController constructor.
     * @param OrderStatusRepository $orderStatusRepository
     * @param ShippingRepository $shippingRepository
     * @param PageMaxRepository $pageMaxRepository
     * @param ProductStatusRepository $productStatusRepository
     * @param ShippingStatusRepository $shippingStatusRepository
     * @param MailService $mailService
     */
    public function __construct(
        OrderStatusRepository $orderStatusRepository,
        ShippingRepository $shippingRepository,
        PageMaxRepository $pageMaxRepository,
        ProductStatusRepository $productStatusRepository,
        ShippingStatusRepository $shippingStatusRepository,
        MailService $mailService
    ) {
        $this->orderStatusRepository = $orderStatusRepository;
        $this->shippingRepository = $shippingRepository;
        $this->pageMaxRepository = $pageMaxRepository;
        $this->productStatusRepository = $productStatusRepository;
        $this->shippingStatusRepository = $shippingStatusRepository;
        $this->mailService = $mailService;
    }


    /**
     * @Route("/%eccube_admin_route%/shipping", name="admin_shipping")
     * @Route("/%eccube_admin_route%/shipping/page/{page_no}", name="admin_shipping_page")
     * @Template("@admin/Shipping/index.twig")
     */
    public function index(Request $request, $page_no = null, Paginator $paginator)
    {
        $session = $request->getSession();

        $builder = $this->formFactory
            ->createBuilder(SearchShippingType::class);

        $event = new EventArgs(
            array(
                'builder' => $builder,
            ),
            $request
        );
        $this->eventDispatcher->dispatch(EccubeEvents::ADMIN_ORDER_INDEX_INITIALIZE, $event);

        $searchForm = $builder->getForm();

        $pagination = array();

        $ProductStatuses = $this->productStatusRepository->findAll();
        $pageMaxis = $this->pageMaxRepository->findAll();
        $page_count = $this->eccubeConfig['eccube_default_page_count'];
        $page_status = null;
        $active = false;

        if ('POST' === $request->getMethod()) {

            $searchForm->handleRequest($request);

            if ($searchForm->isValid()) {
                $searchData = $searchForm->getData();

                // paginator
                $qb = $this->shippingRepository->getQueryBuilderBySearchDataForAdmin($searchData);

                $event = new EventArgs(
                    array(
                        'form' => $searchForm,
                        'qb' => $qb,
                    ),
                    $request
                );
                $this->eventDispatcher->dispatch(EccubeEvents::ADMIN_ORDER_INDEX_SEARCH, $event);

                $page_no = 1;
                $pagination = $paginator->paginate(
                    $qb,
                    $page_no,
                    $page_count
                );

                // sessionのデータ保持
                $session->set('eccube.admin.shipping.search', $searchData);
                $session->set('eccube.admin.shipping.search.page_no', $page_no);
            }
        } else {
            if (is_null($page_no) && $request->get('resume') != Constant::ENABLED) {
                // sessionを削除
                $session->remove('eccube.admin.shipping.search');
                $session->remove('eccube.admin.shipping.search.page_no');
            } else {
                // pagingなどの処理
                $searchData = $session->get('eccube.admin.shipping.search');
                if (is_null($page_no)) {
                    $page_no = intval($session->get('eccube.admin.shipping.search.page_no'));
                } else {
                    $session->set('eccube.admin.shipping.search.page_no', $page_no);
                }

                if (!is_null($searchData)) {

                    // 公開ステータス
                    $status = $request->get('status');
                    if (!empty($status)) {
                        if ($status != $this->eccubeConfig['eccube_admin_product_stock_status']) {
                            $searchData['status']->clear();
                            $searchData['status']->add($status);
                        } else {
                            $searchData['stock_status'] = $this->eccubeConfig['disabled'];
                        }
                        $page_status = $status;
                    }
                    // 表示件数
                    $pcount = $request->get('page_count');

                    $page_count = empty($pcount) ? $page_count : $pcount;

                    $qb = $this->shippingRepository->getQueryBuilderBySearchDataForAdmin($searchData);

                    $event = new EventArgs(
                        array(
                            'form' => $searchForm,
                            'qb' => $qb,
                        ),
                        $request
                    );
                    $this->eventDispatcher->dispatch(EccubeEvents::ADMIN_ORDER_INDEX_SEARCH, $event);

                    $pagination = $paginator->paginate(
                        $qb,
                        $page_no,
                        $page_count
                    );

                    if (isset($searchData['order_status']) && count($searchData['order_status']) > 0) {
                        $statusIds = array();
                        foreach ($searchData['order_status'] as $Status) {
                            $statusIds[] = $Status->getId();
                        }
                        $searchData['order_status'] = $this->orderStatusRepository->findBy(array('id' => $statusIds));
                    }
                    if (isset($searchData['shipping_status']) && count($searchData['shipping_status']) > 0) {
                        $statusIds = array();
                        foreach ($searchData['shipping_status'] as $Status) {
                            $statusIds[] = $Status->getId();
                        }
                        $searchData['shipping_status'] = $this->shippingStatusRepository->findBy(array('id' => $statusIds));
                    }

                    $searchForm->setData($searchData);
                }
            }
        }

        return [
            'searchForm' => $searchForm->createView(),
            'pagination' => $pagination,
            'productStatuses' => $ProductStatuses,
            'pageMaxis' => $pageMaxis,
            'page_no' => $page_no,
            'page_status' => $page_status,
            'page_count' => $page_count,
            'active' => $active,
        ];
    }

    /**
     * @Method("PUT")
     * @Route("/%eccube_admin_route%/shipping/mark_as_shipped/{id}", requirements={"id" = "\d+"}, name="admin_shipping_mark_as_shipped")
     * @param Request $request
     * @param Shipping $Shipping
     * @return JsonResponse
     * @throws \Twig_Error
     */
    public function markAsShipped(Request $request, Shipping $Shipping)
    {
        if ($Shipping->getShippingStatus()->getId() !== ShippingStatus::SHIPPED) {
            /** @var ShippingStatus $StatusShipped */
            $StatusShipped = $this->shippingStatusRepository->find(ShippingStatus::SHIPPED);
            $Shipping->setShippingStatus($StatusShipped);
            $this->shippingRepository->save($Shipping);

            if ($request->get('notificationMail')) {
                $this->mailService->sendShippingNotifyMail($Shipping);
            }

            $this->entityManager->flush();
        }

        return new JsonResponse(['success' => true]);
    }

    /**
     * @Route("/%eccube_admin_route%/shipping/preview_notify_mail/{id}", requirements={"id" = "\d+"}, name="admin_shipping_preview_notify_mail")
     * @param Shipping $Shipping
     * @return Response
     * @throws \Twig_Error
     */
    public function previewShippingNotifyMail(Shipping $Shipping)
    {
        return new Response($this->mailService->getShippingNotifyMailBody($Shipping, $Shipping->getOrders()->first()));
    }
}
