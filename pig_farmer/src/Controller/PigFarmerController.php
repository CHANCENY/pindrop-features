<?php

namespace Simp\Pindrop\Modules\pig_farmer\src\Controller;

use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Message\Message;
use Simp\Pindrop\Modules\pig_farmer\src\Services\FinanceManager;
use Simp\Pindrop\Modules\pig_farmer\src\Services\PigManager;
use Simp\Pindrop\Modules\pig_farmer\src\Services\HealthRecordManager;
use Simp\Pindrop\Modules\pig_farmer\src\Services\FeedingLogManager;
use Simp\Pindrop\Routing\Url;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

class PigFarmerController extends ControllerBase
{
    private PigManager $pigManager;
    private HealthRecordManager $healthRecordManager;
    private FeedingLogManager $feedingLogManager;
    private FinanceManager $financeManager;

    public function __construct(PigManager $pigManager, HealthRecordManager $healthRecordManager, FeedingLogManager $feedingLogManager, FinanceManager $financeManager)
    {
        $this->pigManager = $pigManager;
        $this->healthRecordManager = $healthRecordManager;
        $this->feedingLogManager = $feedingLogManager;
        $this->financeManager = $financeManager;

        parent::__construct();
    }

    public static function create($container): PigFarmerController
    {
        return new self(
            $container->get("pig_farmer.pig_manager"),
            $container->get("pig_farmer.health_record_manager"),
            $container->get("pig_farmer.feeding_log_manager"),
            $container->get("pig_farmer.finance_manager")
        );
    }

    /**
     * @throws DatabaseException
     */
    public function dashboard(Request $request, string $route_name, array $options): Response
    {
        $pigs = $this->pigManager->getAllPigs();
        $financeSummary = $this->financeManager->getFinancialSummary();
        return $this->renderTwig("@pig_farmer/admin/dashboard.twig", ["pigs" => $pigs, "financeSummary" => $financeSummary]);
    }

    public function home(Request $request, string $route_name, array $options): Response
    {
        return $this->renderTwig("@pig_farmer/admin/home.html.twig");
    }

    /**
     * @throws DatabaseException
     */
    public function listPigs(Request $request, string $route_name, array $options): Response
    {
        $pigs = $this->pigManager->getAllPigs();
        return $this->renderTwig("@pig_farmer/admin/pigs/list.twig", ["pigs" => $pigs]);
    }

    /**
     * @throws DatabaseException
     */
    public function addPig(Request $request, string $route_name, array $options): Response
    {
        if ($request->isMethod("POST")) {
            $data = $request->request->all();
            if ($this->pigManager->addPig($data)) {
                return $this->redirect($this->generateUrl("pig_farmer.admin_pigs"));
            }
        }
        return $this->renderTwig("@pig_farmer/admin/pigs/add.twig");
    }

    /**
     * @throws DatabaseException
     */
    public function editPig(Request $request, string $route_name, array $options): Response
    {
        $id = $request->query->get('id');
        $pig = $this->pigManager->getPigById($id);
        if (!$pig) {
            return $this->createNotFoundException("Pig not found");
        }

        if ($request->isMethod("POST")) {
            $data = $request->request->all();
            if ($this->pigManager->updatePig($id, $data)) {
                Message::info("Pig updated");
                return new RedirectResponse($this->generateUrl("pig_farmer.admin_pigs"));
            }
        }
        return $this->renderTwig("@pig_farmer/admin/pigs/edit.twig", ["pig" => $pig]);
    }

    /**
     * @throws DatabaseException
     */
    public function deletePig(Request $request, string $route_name, array $options): Response
    {
        $id = $request->query->get('id');
        if ($request->isMethod("POST")) {
            if ($this->pigManager->deletePig($id)) {
                return new RedirectResponse($this->generateUrl("pig_farmer.admin_pigs"));
            }
        }
        return new RedirectResponse($this->generateUrl("pig_farmer.admin_pigs"));
    }

    /**
     * @throws DatabaseException
     */
    public function listHealthRecords(Request $request, string $route_name, array $options): Response
    {
        $pigId = $request->query->get('pigId');
        $pig = $this->pigManager->getPigById($pigId);
        if (!$pig) {
            return $this->createNotFoundException("Pig not found");
        }
        $healthRecords = $this->healthRecordManager->getHealthRecordsByPigId($pigId);
        return $this->renderTwig("@pig_farmer/admin/health_records/list.twig", ["pig" => $pig, "healthRecords" => $healthRecords]);
    }

    /**
     * @throws DatabaseException
     */
    public function addHealthRecord(Request $request, string $route_name, array $options): Response
    {
        $pigId = $request->query->get('pigId');
        $pig = $this->pigManager->getPigById($pigId);
        if (!$pig) {
            return $this->createNotFoundException("Pig not found");
        }

        if ($request->isMethod("POST")) {
            $data = $request->request->all();
            $data["pig_id"] = $pigId;
            if ($this->healthRecordManager->addHealthRecord($data)) {
                Message::info("Health record created");
                return new RedirectResponse($this->generateUrl("pig_farmer.admin_health_records", ["pigId" => $pigId]));
            }
        }
        return $this->renderTwig("@pig_farmer/admin/health_records/add.twig", ["pig" => $pig]);
    }

    public function addHealthRecords(Request $request, string $route_name, array $options): Response
    {
        $pigs = $this->pigManager->getAllPigs();
        $pigs = array_filter($pigs, function ($pig) {
            return $pig['status'] === 'Active';
        });

        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            foreach ($pigs as $pig) {
                $data['pig_id'] = $pig['id'];
                if ($this->healthRecordManager->addHealthRecord($data)) {
                    Message::info('Health record created');
                }
            }
        }

        return $this->renderTwig("@pig_farmer/admin/health_records/add_bulk.twig",
         ['pigs'=> $pigs]);
    }

    /**
     * @throws DatabaseException
     */
    public function listFeedingLogs(Request $request, string $route_name, array $options): Response
    {
        $pigId = $request->query->get('pigId');
        $pig = $this->pigManager->getPigById($pigId);
        if (!$pig) {
            return $this->createNotFoundException("Pig not found");
        }
        $feedingLogs = $this->feedingLogManager->getFeedingLogsByPigId($pigId);
        return $this->renderTwig("@pig_farmer/admin/feeding_logs/list.twig", ["pig" => $pig, "feedingLogs" => $feedingLogs]);
    }

    /**
     * @throws DatabaseException
     */
    public function addFeedingLog(Request $request, string $route_name, array $options): Response
    {
        $pigId = $request->query->get('pigId');
        $pig = $this->pigManager->getPigById($pigId);
        if (!$pig) {
            return $this->createNotFoundException("Pig not found");
        }

        if ($request->isMethod("POST")) {
            $data = $request->request->all();
            $data["pig_id"] = $pigId;
            if ($this->feedingLogManager->addFeedingLog($data)) {
                return new RedirectResponse($this->generateUrl("pig_farmer.admin_feeding_logs", ["pigId" => $pigId]));
            }
        }
        return $this->renderTwig("@pig_farmer/admin/feeding_logs/add.twig", ["pig" => $pig]);
    }

    private function generateUrl(string $name, array $parameters = [], bool $absolute = false): string
    {
        return Url::routeByName($name, $parameters, $absolute);
    }

    private function createNotFoundException(string $string): Response
    {
        Message::error($string);
        return $this->redirect($this->generateUrl("pig_farmer.admin_dashboard"));
    }

    /**
     * @throws DatabaseException
     */
    public function addFeedingLogBulk(Request $request, string $route_name, array $options): Response
    {
        $pigs = $this->pigManager->getAllPigs();
        $pigs = array_filter($pigs, function ($pig) {
            return $pig['status'] === 'Active';
        });

        if ($request->isMethod("POST")) {
            $data = $request->request->all();
            foreach ($pigs as $pig) {
                if (in_array($pig['id'], $data['pigs'])) {

                    $this->feedingLogManager->addFeedingLog([
                        'pig_id' => $pig['id'],
                        'feed_date' => $data['feed_date'],
                        'quantity'  => $data['quantity'],
                        'feed_type' => $data['feed_type'],
                    ]);
                }
            }
            Message::info("Feeding logs created");
            return $this->redirect($this->generateUrl("pig_farmer.admin_pigs"));
        }

        return $this->renderTwig("@pig_farmer/admin/feeding_logs/bulk.twig", ['pigs' => $pigs]);
    }

    /**
     * @throws DatabaseException
     */
    public function listFinances(Request $request, string $route_name, array $options): Response
    {
        $finances = $this->financeManager->getAllFinances();
        $financeSummary = $this->financeManager->getFinancialSummary();
        return $this->renderTwig("@pig_farmer/admin/finances/list.twig", ["finances" => $finances, "financeSummary" => $financeSummary]);
    }

    /**
     * @throws DatabaseException
     */
    public function addFinanceRecord(Request $request, string $route_name, array $options): Response
    {
        if ($request->isMethod("POST")) {
            $data = $request->request->all();
            $data["pig_id"] = $data["pig_id"] === "" ? null : (int)$data["pig_id"];
            if ($this->financeManager->addFinanceRecord($data)) {
                return new RedirectResponse($this->generateUrl("pig_farmer.admin_finances"));
            }
        }
        $pigs = $this->pigManager->getAllPigs();
        return $this->renderTwig("@pig_farmer/admin/finances/add.twig", ["pigs" => $pigs]);
    }

    /**
     * @throws DatabaseException
     */
    public function editFinanceRecord(Request $request, string $route_name, array $options): Response
    {
        $id = $request->query->get('id');
        $finance = $this->financeManager->getFinanceById($id);
        if (!$finance) {
            return $this->createNotFoundException("pig_farmer.admin_finances");
        }

        if ($request->isMethod("POST")) {
            $data = $request->request->all();
            $data["pig_id"] = $data["pig_id"] === "" ? null : (int)$data["pig_id"];
            if ($this->financeManager->updateFinanceRecord($id, $data)) {
                return new RedirectResponse($this->generateUrl("pig_farmer.admin_finances"));
            }
        }
        $pigs = $this->pigManager->getAllPigs();
        return $this->renderTwig("@pig_farmer/admin/finances/edit.twig", ["finance" => $finance, "pigs" => $pigs]);
    }

    /**
     * @throws DatabaseException
     */
    public function deleteFinanceRecord(Request $request, string $route_name, array $options): Response
    {
        $id = $request->query->get('id');
        if ($request->isMethod("POST")) {
            if ($this->financeManager->deleteFinanceRecord($id)) {
                return new RedirectResponse($this->generateUrl("pig_farmer.admin_finances"));
            }
        }
        return new RedirectResponse($this->generateUrl("pig_farmer.admin_finances"));
    }


}
