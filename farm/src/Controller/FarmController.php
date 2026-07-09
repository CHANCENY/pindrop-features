<?php

namespace Simp\Pindrop\Modules\farm\src\Controller;

use DateTime;
use Psr\Container\ContainerInterface;
use Shuchkin\SimpleCSV;
use Shuchkin\SimpleXLSX;
use Shuchkin\SimpleXLSXGen;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Entity\User\CurrentUser;
use Simp\Pindrop\Message\Message;
use Simp\Pindrop\Modules\farm\src\Services\Barn;
use Simp\Pindrop\Modules\farm\src\Services\Dashboard;
use Simp\Pindrop\Modules\farm\src\Services\Facility;
use Simp\Pindrop\Modules\farm\src\Services\Insemination;
use Simp\Pindrop\Modules\farm\src\Services\Inventory;
use Simp\Pindrop\Modules\farm\src\Services\InventoryFeed;
use Simp\Pindrop\Modules\farm\src\Services\Pen;
use Simp\Pindrop\Modules\farm\src\Services\Pig;
use Simp\Pindrop\Modules\farm\src\Services\PigWeightRecord;
use Simp\Pindrop\Modules\farm\src\Services\PurchaseOrder;
use Simp\Pindrop\Modules\farm\src\Services\Transaction;
use Simp\Pindrop\Modules\farm\src\Services\Treatment;
use Simp\Pindrop\Modules\farm\src\Services\Vaccination;
use Simp\Pindrop\Modules\signals_slots\src\Service\SignalBus;
use Simp\Pindrop\Routing\Url;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class FarmController extends ControllerBase
{

    public function __construct(
        protected Facility $facility_service,
        protected Barn $barn_service,
        protected Pen $pen_service,
        protected Pig $pig_service,
        protected PigWeightRecord $pigWeightRecord,
        protected Treatment $treatment_service,
        protected Vaccination $vaccination_service,
        protected Insemination $insemination_service,
        protected InventoryFeed $inventoryFeed_service,
        protected Transaction $financial_service,
        protected PurchaseOrder $purchaseOrder_service,
        protected Inventory $inventory_service,
        protected Dashboard $dashboard_service,
        protected ?CurrentUser $currentUser,
        protected SignalBus    $signalBus
    ) {
        return parent::__construct();
    }

    public static function create(ContainerInterface $container): FarmController
    {
        return new static(
            $container->get('farm.facility'),
            $container->get('farm.barn'),
            $container->get('farm.pen'),
            $container->get('farm.pig'),
            $container->get('farm.weight'),
            $container->get('farm.health'),
            $container->get('farm.vaccination'),
            $container->get('farm.insemination'),
            $container->get('farm.invetory.feed'),
            $container->get('farm.financial'),
            $container->get('farm.purchase_order'),
            $container->get('farm.inventory'),
            $container->get('farm.dashboard'),
            $container->get('current_user'),
            $container->get(SignalBus::class)

        );
    }

    public function facilities(Request $request, string $route_name, array $options)
    {
        // Implementation for handling facilities route
        return $this->renderTwig("@farm/farm/facilities.html.twig", [
            'facilities' => $this->facility_service->getAllFacilities(),
        ]);
    }

    public function addFacility(Request $request, string $route_name, array $options)
    {
        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            unset($data['_csrf_token']); // Remove CSRF token from the data

            $facility_id = $this->facility_service->createFacility($data);
            if ($facility_id) {
                $this->signalBus->emit('farm.facility.created', [
                    'facility' => $this->facility_service->getFacilityById($facility_id)
                ]);

                // Redirect to the facilities list after successful creation
                return $this->redirect(Url::routeByName('farm.erp.facilities'));
            } else {
                // Handle error (e.g., show an error message)
                Message::error("Failed to create facility. Please try again.");
            }

        }

        return $this->renderTwig("@farm/farm/facility-add.html.twig");
    }

    public function manageFacility(Request $request, string $route_name, array $options)
    {
        $facility_id = $request->query->get('id');
        if (!$facility_id) {
            Message::error("Facility ID is required.");
            return $this->redirect(Url::routeByName('farm.erp.facilities'));
        }

        $facility = $this->facility_service->getFacilityById($facility_id);
        if (!$facility) {
            Message::error("Facility not found.");
            return $this->redirect(Url::routeByName('farm.erp.facilities'));
        }

        // Handle any POST requests for managing the facility here
        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            unset($data['_csrf_token']); // Remove CSRF token from the data

            $data['current_load'] = ((int) $data['barns_count'] / (int) $data['capacity']) * 100;
            $update_success = $this->facility_service->updateFacility($facility_id, $data);
            if ($update_success) {
                $this->signalBus->emit('farm.facility.updated', [
                    'facility_id' => $facility_id,
                    'facility' => $data,
                ]);
                Message::info("Facility updated successfully.");
                return $this->redirect(Url::routeByName('farm.erp.facilities'));
            } else {
                Message::error("Failed to update facility. Please try again.");
            }
        }

        return $this->renderTwig("@farm/farm/facility-manage.html.twig", [
            'facility' => $facility,
            'barns' => $this->barn_service->getBarnsByFacilityId($facility_id),
        ]);
    }

    public function useBarns(Request $request, string $route_name, array $options)
    {
        // Implementation for handling use barn route
        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            unset($data['_csrf_token']); // Remove CSRF token from the data
            $data['current_load'] = 0; // Initialize current load to 0 for a new barn
            if (!empty($data['capacity']) && !empty($data['pens_count'])) {
                $data['current_load'] = ((int) $data['pens_count'] / (int) $data['capacity']) * 100;
            }

            $barn_id = $this->barn_service->createBarn($data);
            if ($barn_id) {
                $this->signalBus->emit('farm.barn.created', [
                    'barn_id' => $barn_id,
                    'facility_id' => $data['facility_id'],
                    'barn' => $data,
                ]);
                Message::info("Barn created successfully.");
            } else {
                Message::error("Failed to create barn. Please try again.");
            }
            return $this->redirect(Url::routeByName('farm.erp.facility.manage', ['id' => $data['facility_id']]));
        }

        return $this->renderTwig("@farm/farm/use-barn.html.twig", [
            'barns' => $this->barn_service->getAllBarns(),
            'facility_id' => $request->query->get('facility_id'),
        ]);
    }

    public function editBarn(Request $request, string $route_name, array $options)
    {
        $facility_id = $request->query->get('facility_id');
        $barn_id = $request->query->get('barn_id');

        if (!$facility_id || !$barn_id) {
            Message::error("Facility ID and Barn ID are required.");
            return $this->redirect(Url::routeByName('farm.erp.facilities'));
        }

        $barn = $this->barn_service->getBarnById($barn_id);
        if (!$barn) {
            Message::error("Barn not found.");
            return $this->redirect(Url::routeByName('farm.erp.facility.manage', ['id' => $facility_id]));
        }

        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            unset($data['_csrf_token']); // Remove CSRF token from the data
            if (!empty($data['capacity']) && !empty($data['pens_count'])) {
                $data['current_load'] = ((int) $data['pens_count'] / (int) $data['capacity']) * 100;
            }

            $update_success = $this->barn_service->updateBarn($barn_id, $data);
            if ($update_success) {
                $this->signalBus->emit('farm.barn.updated', [
                    'barn_id' => $barn_id,
                    'barn' => $data,
                ]);
                Message::info("Barn updated successfully.");
            } else {
                Message::error("Failed to update barn. Please try again.");
            }
            return $this->redirect(Url::routeByName('farm.erp.facility.manage', ['id' => $facility_id]));
        }

        return $this->renderTwig("@farm/farm/edit-barn.html.twig", [
            'barn' => $barn,
            'facility_id' => $facility_id,
        ]);
    }

    public function managePens(Request $request, string $route_name, array $options)
    {
        $barn_id = $request->query->get('barn_id');
        if (!$barn_id) {
            Message::error("Barn ID is required.");
            return $this->redirect(Url::routeByName('farm.erp.facilities'));
        }

        $pens = $this->pen_service->getPensByBarnId($barn_id);
        return $this->renderTwig("@farm/farm/manage-pens.html.twig", [
            'pens' => $pens,
            'barn_id' => $barn_id,
            'barn' => $this->barn_service->getBarnById($barn_id),
        ]);
    }

    public function addPen(Request $request, string $route_name, array $options)
    {
        $barn_id = $request->query->get('barn_id');
        if (!$barn_id) {
            Message::error("Barn ID is required.");
            return $this->redirect(Url::routeByName('farm.erp.facilities'));
        }

        $barn = $this->barn_service->getBarnById($barn_id);

        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            unset($data['_csrf_token']); // Remove CSRF token from the data
            $data['barn_id'] = $barn_id;

            $pen_id = $this->pen_service->createPen($data);
            if ($pen_id) {
                $this->signalBus->emit('farm.pen.created', [
                    'pen_id' => $pen_id,
                    'barn_id' => $barn_id,
                    'pen' => $data,
                ]);
                Message::info("Pen created successfully.");
            } else {
                Message::error("Failed to create pen. Please try again.");
            }
            return $this->redirect(Url::routeByName('farm.erp.facility.manage', ['id' => $barn['facility_id']]));
        }

        return $this->renderTwig("@farm/farm/add-pen.html.twig", [
            'barn_id' => $barn_id,
            'barn' => $barn,
        ]);
    }

    public function updatePenCurrentLoad(Request $request, string $route_name, array $options)
    {
        if ($request->isMethod('GET')) {
            $data = $request->query->all();

            if (!isset($data['pen_id']) || !isset($data['current_load'])) {
                return new JsonResponse(['error' => 'Missing required parameters.'], 400);
            }

            $pen_id = $data['pen_id'];
            $current_load = (int) $data['current_load'];

            // Update the current load of the pen
            $update_success = $this->pen_service->updatePen($pen_id, ['current_load' => $current_load]);

            if ($update_success) {
                $this->signalBus->emit('farm.pen.updated', [
                    'pen_id' => $pen_id,
                    'current_load' => $current_load,
                ]);
                return new JsonResponse(['success' => true, 'message' => 'Current load updated successfully.']);
            } else {
                return new JsonResponse(['error' => 'Failed to update current load.'], 500);
            }
        }

        return new JsonResponse(['error' => 'Invalid request method.'], 405);
    }

    public function managePigs(Request $request, string $route_name, array $options)
    {
        // Implementation for handling manage pigs route
        return $this->renderTwig("@farm/farm/manage-pigs.html.twig", [
            'pigs' => $this->pig_service->getAllPigs(),
            'facilities' => $this->facility_service->getAllFacilities(),
            'sexes' => $this->pig_service->getSexes(),
            'health_statuses' => $this->pig_service->getHealthStatuses(),
        ]);
    }

    public function addPig(Request $request, string $route_name, array $options)
    {
        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            unset($data['_csrf_token']); // Remove CSRF token from the data

            $location = $data['location'] ?? null;
            if (!empty($location)) {
                $list = explode('/', $location);
                $pen = $this->pen_service->getPenByName(trim(end($list)));
                $dd = [
                    'date_of_birth' => $data['dob'],
                    'pig_id' => $data['tag'],
                    'facility_id' => $data['farm'],
                    'breed' => $data['breed'],
                    'sex' => $data['sex'],
                    'barn_id' => $this->barn_service->getBarnByName(trim($list[0]))['barn_id'] ?? 0,
                    'pen_id' => $pen['pen_id'] ?? 0,
                    'location_label' => $data['location'] ?? "",
                    'current_weight_kg' => $data['weight'] ?? "1",
                    'health_status' => $data['status'],
                    'notes' => $data['notes'] ?? "",
                    'sire_id' => $data['sire'],
                    'dam_id' => $data['dam']
                ];
                $pig_id = $this->pig_service->createPig($dd);

                if ($pig_id) {

                    $this->signalBus->emit('farm.pig.created', [
                        'pig_id' => $dd['pig_id'],
                        'facility_id' => $dd['facility_id'],
                        'pen_id' => $dd['pen_id'],
                    ]);

                    $this->pen_service->updatePen($dd['pen_id'], [
                        'current_load' => ((int) $pen['current_load'] ?? 0) + 1
                    ]);
                    if (!$this->vaccination_service->isAnimalGroupVaccinationSchedulesExists($dd['pen_id'])) {
                        $this->vaccination_service->addVaccinationBatch($pen['name'], $dd['date_of_birth']);

                    }
                    $this->vaccination_service->addAnimalToVaccinationGroup($dd['pen_id'], $dd['pig_id']);

                    Message::info("Pig added successfully.");
                    return $this->redirect(Url::routeByName('farm.erp.livestock.pigs'));
                } else {
                    Message::error("Failed to add pig. Please try again.");
                }
            }
        }


        return $this->renderTwig(
            "@farm/farm/add-pig.html.twig",
            [
                'facilities' => $this->facility_service->getAllFacilities(),
            ]
        );
    }

    public function editPig(Request $request, string $route_name, array $options)
    {
        $id = $request->query->get('id');

        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            unset($data['_csrf_token']); // Remove CSRF token from the data

            $location = $data['location'] ?? null;
            if (!empty($location)) {
                $list = explode('/', $location);
                $pen = $this->pen_service->getPenByName(trim(end($list)));
                $dd = [
                    'date_of_birth' => $data['dob'],
                    'pig_id' => $data['tag'],
                    'facility_id' => $data['farm'],
                    'breed' => $data['breed'],
                    'sex' => $data['sex'],
                    'barn_id' => $this->barn_service->getBarnByName(trim($list[0]))['barn_id'] ?? 0,
                    'pen_id' => $pen['pen_id'] ?? 0,
                    'location_label' => $data['location'] ?? "",
                    'current_weight_kg' => $data['weight'] ?? "1",
                    'health_status' => $data['status'],
                    'notes' => $data['notes'] ?? "",
                    'sire_id' => $data['sire'],
                    'dam_id' => $data['dam']
                ];
                $pig_id = $this->pig_service->updatePig($id, $dd);

                if ($pig_id) {
                    $this->signalBus->emit('farm.pig.updated', [
                        'pig_id' => $dd['pig_id'],
                        'pig' => $dd,
                    ]);
                    Message::info("Pig updated successfully.");
                    return $this->redirect(Url::routeByName('farm.erp.livestock.pigs'));
                } else {
                    Message::error("Failed to update pig. Please try again.");
                }
            }
        }

        return $this->renderTwig(
            "@farm/farm/edit-pig.html.twig",
            [
                'facilities' => $this->facility_service->getAllFacilities(),
                'pig' => $this->pig_service->getPigById($id)
            ]
        );
    }

    public function viewPig(Request $request, string $route_name, array $options)
    {
        $id = $request->query->get('id');

        $history = $this->insemination_service->getBreedingHistory($id);

        $history_breakdown = [];
        foreach ($history as $hist) {
            $history_breakdown[] = [
                'event' => $hist['method'],
                'date' => $hist['insemination_date'],
                'pig' => $hist['sow_id'] . " / " . $hist['boar_id'],
                'outcome' => $hist['status']
            ];

            if (!empty($hist['piglets_alive'])) {
                $history_breakdown[] = [
                    'event' => "Farrowing",
                    'date' => $hist['farrowing_date'],
                    'pig' => $hist['sow_id'] . " / " . $hist['boar_id'],
                    'outcome' => $hist['piglets_alive'] . " piglets"
                ];
            }
        }

        return $this->renderTwig(
            "@farm/farm/view-pig.html.twig",
            [
                'pig' => $this->pig_service->getPigById($id),
                'weights' => $this->pigWeightRecord->getPigWeightRecords($id),
                'treatments' => $this->treatment_service->getPigTreatments($id),
                'history' => $history_breakdown
            ]
        );
    }

    public function addWeight(Request $request, string $route_name, array $options)
    {
        $id = $request->query->get('id');
        $pig = $this->pig_service->getPigById($id);
        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            unset($data['_csrf_token']);

            $weight_id = $this->pigWeightRecord->createWeightRecord($data);
            if ($weight_id) {
                $this->pig_service->updatePig($id, ['current_weight_kg' => $data['weight_kg']]);
                $this->signalBus->emit('farm.pig.weight_recorded', [
                    'pig_id' => $pig['pig_id'],
                    'weight_kg' => $data['weight_kg'],
                ]);
                Message::info("Weight add successfully");

            } else {
                Message::error("Failed to save weight");
            }
            return $this->redirect(Url::routeByName('farm.erp.livestock.pigs.view', ['id' => $pig['pig_id']]));
        }
        return $this->renderTwig(
            "@farm/farm/weight-add.html.twig",
            [
                'pig' => $pig,
            ]
        );
    }

    public function health(Request $request, string $route_name, array $options)
    {
        $vaccinations = $this->vaccination_service->getAllVaccinations();

        $events = [];

        foreach ($vaccinations as $vaccination) {

            $color = match ($vaccination['status']) {
                'Completed' => '#28a745',
                'Overdue' => '#dc3545',
                default => '#0d6efd',
            };

            $events[] = [
                'id' => $vaccination['vaccination_id'],
                'title' => $vaccination['vaccine_type'],
                'start' => $vaccination['scheduled_date'],
                'color' => $color,
                'extendedProps' => [
                    'animal' => $vaccination['animal_group'],
                    'batch' => $vaccination['batch_id'],
                    'status' => $vaccination['status'],
                    'pigs' => array_map(function ($pig) {
                        return $pig['pig_id'];
                    }, $this->vaccination_service->getAnimalOnGroup($vaccination['animal_group'])),

                ],
                'vet' => $vaccination['assigned_to']
            ];
        }

        $statics = $this->vaccination_service->getOverallStatics();

        return $this->renderTwig("@farm/farm/health.html.twig", [
            'treatments' => $this->treatment_service->getAllTreatments(),
            'vaccinations' => $vaccinations,
            'events' => $events,
            'statics' => $statics
        ]);
    }

    public function addHealth(Request $request, string $route_name, array $options)
    {
        $pig_id = $request->query->get('pid');

        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            unset($data['_csrf_token']);

            $dd = [
                'diagnosis' => $data['diagnosis'],
                'treatment' => $data['treatment'],
                'dosage' => $data['dosage'],
                'treatment_date' => $data['date'],
                'attending_vet' => $data['vet'],
                'duration_days' => $data['duration'],
                'outcome' => $data['outcome'],
                'notes' => $data['notes']
            ];

            $tag = $data['pig_id'] ?? "";
            $pigs = [];
            if (str_starts_with($tag, "BATCH")) {
                $last = substr($tag, strripos($tag, "(") + 1, strlen($tag));
                $pen_id = trim($last, ")");

                if (is_numeric($pen_id)) {
                    $pigs = array_filter(array_map(function ($pig) {
                        return $pig['pig_id'] ?? null;
                    }, $this->pig_service->getPigsByPenId($pen_id)));
                }
            } elseif (!empty($tag)) {
                $pigs[] = $tag;
            }

            $flags = [];

            $pig_health_status = [
                'Under Treatment' => 'Quarantine',
                'Recovered' => 'Healthy',
                'Ongoing Monitoring' => 'Under Observation',
                'Deceased' => 'Deceased'
            ];
            foreach ($pigs as $pig) {
                $dd['pig_id'] = $pig;
                if ($this->treatment_service->createTreatment($dd)) {
                    $flags[] = $pig;
                    $pig_status = $pig_health_status[$dd['outcome']] ?? null;
                    if ($pig_status) {
                        $this->pig_service->updatePig($pig, ['health_status' => $pig_status]);
                    }
                }
            }

            if (!empty($flags)) {
                $this->signalBus->emit('farm.health.treatment_created', [
                    'pig_ids' => $flags,
                    'diagnosis' => $dd['diagnosis'],
                    'treatment' => $dd['treatment'],
                ]);
                Message::info("Health treatment records for pig(s) " . implode(',', $flags));
            }
            return $this->redirect(Url::routeByName('farm.erp.health.lists'));

        }

        return $this->renderTwig("@farm/farm/add-health.html.twig", [
            'pig' => $pig_id,
        ]);

    }

    public function updateHealth(Request $request, string $route_name, array $options)
    {
        $id = $request->query->get('id');
        $vet = $request->query->get('name');
        $status = $request->query->get('status');
        $this->vaccination_service->updateVaccination($id, $vet, $status);
        $this->signalBus->emit('farm.vaccination.updated', [
            'vaccination_id' => $id,
            'status' => $status,
        ]);
        return new JsonResponse(['status' => true]);
    }

    public function inseminations(Request $request, string $route_name, array $options)
    {
        return $this->renderTwig("@farm/farm/inseminations.html.twig", [
            'inseminations' => $this->insemination_service->getAllInseminations(),
            'statics' => $this->insemination_service->getInseminationStatics()
        ]);
    }

    public function addInseminations(Request $request, string $route_name, array $options)
    {
        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            unset($data['_csrf_token']);

            $now = new DateTime();
            $expected_date = $now->modify("+114 days");

            $in_id = $this->insemination_service->createInsemination([
                'insemination_date' => $data['date'],
                'facility_id' => $data['farm'],
                'location_label' => $data['location'],
                'method' => $data['method'],
                'sow_id' => $data['sow_id'],
                'boar_id' => $data['boar_id'],
                'semen_batch' => $data['semen_batch'] ?? null,
                'technician' => $data['technician'],
                'status' => $data['status'],
                'notes' => $data['notes'],
                'expected_due_date' => $expected_date->format('Y-m-d h:i:s'),
            ]);

            if ($in_id) {
                $this->signalBus->emit('farm.insemination.created', [
                    'insemination_id' => $in_id,
                    'sow_id' => $data['sow_id'],
                    'boar_id' => $data['boar_id'],
                ]);
            }

            if (!$in_id) {
                Message::info("Insemination done");
                return $this->redirect(Url::routeByName('farm.erp.inseminations'));
            }
        }


        return $this->renderTwig("@farm/farm/add-insemination.html.twig", [
            'inseminations' => $this->insemination_service->getAllInseminations(),
            'facilities' => $this->facility_service->getAllFacilities()
        ]);
    }

    public function editInseminations(Request $request, string $route_name, array $options)
    {

        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            unset($data['_csrf_token']);

            $now = new DateTime();
            $expected_date = $now->modify("+114 days");

            $in_id = $this->insemination_service->updateInsemination($request->query->getInt('id'), [
                'insemination_date' => $data['date'],
                'facility_id' => $data['farm'],
                'location_label' => $data['location'],
                'method' => $data['method'],
                'sow_id' => $data['sow_id'],
                'boar_id' => $data['boar_id'],
                'semen_batch' => $data['semen_batch'] ?? null,
                'technician' => $data['technician'],
                'status' => $data['status'],
                'notes' => $data['notes'],
                'expected_due_date' => $expected_date->format('Y-m-d h:i:s'),
            ]);

            if ($in_id) {
                $this->signalBus->emit('farm.insemination.updated', [
                    'insemination_id' => $request->query->getInt('id'),
                ]);
                Message::info("Insemination done");
                return $this->redirect(Url::routeByName('farm.erp.inseminations'));
            }
        }

        return $this->renderTwig("@farm/farm/edit-insemination.html.twig", [
            'inseminations' => $this->insemination_service->getAllInseminations(),
            'facilities' => $this->facility_service->getAllFacilities(),
            'insemination' => $this->insemination_service->getInseminationById($request->query->getInt('id')),
        ]);
    }

    public function addForrowing(Request $request, string $route_name, array $options)
    {
        $insemination_id = $request->query->getInt('insemination_id');
        $insemination = $this->insemination_service->getInseminationById($insemination_id);

        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            unset($data['_csrf_token']);
            if ($this->insemination_service->addFarrowing($data)) {
                $this->signalBus->emit('farm.farrowing.created', [
                    'insemination_id' => $insemination_id,
                    'farrowing' => $data,
                ]);
                Message::info("Farrowing added");
            }
            return $this->redirect(Url::routeByName('farm.erp.inseminations'));
        }

        return $this->renderTwig("@farm/farm/add-forrowing.html.twig", [
            'insemination' => $insemination
        ]);
    }

    public function viewInseminations(Request $request, string $route_name, array $options)
    {

        $insemination_id = $request->query->getInt('insemination_id');
        $insemination = $this->insemination_service->getInseminationById($insemination_id);
        $farrowing = $this->insemination_service->getFarrowingByInsemination($insemination_id);

        return $this->renderTwig("@farm/farm/view-forrowing.html.twig", [
            'insemination' => $insemination,
            'farrowing' => $farrowing,
            'facilities' => $this->facility_service->getAllFacilities(),
            'piglets' => $this->insemination_service->getPigletsByFarrowingId($farrowing['farrowing_id']),
        ]);
    }

    public function addFarrowingPiglet(Request $request, string $route_name, array $options)
    {
        $farrowing_id = $request->query->getInt('farrowing_id');
        $insemination_id = $request->query->getInt('insemination_id');

        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            unset($data['_csrf_token']);

            $farrowing = $this->insemination_service->getFarrowingByInsemination($insemination_id);
            $insemination = $this->insemination_service->getInseminationById($insemination_id);

            $pigs = [];
            for ($i = 1; $i <= $farrowing['piglets_alive']; $i++) {
                if (!empty($data['tag'][$i])) {
                    $list = explode('/', $data['location'][$i]);
                    $pigs[] = [
                        'pig_id' => $data['tag'][$i],
                        'date_of_birth' => $data['dob'][$i],
                        'facility_id' => $data['farm'][$i],
                        'breed' => $data['breed'][$i],
                        'sex' => $data['sex'][$i],
                        'barn_id' => $this->barn_service->getBarnByName(trim($list[0]))['barn_id'] ?? 0,
                        'pen_id' => $this->pen_service->getPenByName(trim(end($list)))['pen_id'] ?? 0,
                        'location_label' => $data['location'][$i] ?? "",
                        'current_weight_kg' => $data['weight'][$i] ?? "1",
                        'health_status' => $data['status'][$i],
                        'sire_id' => $insemination['boar_id'],
                        'dam_id' => $insemination['sow_id']
                    ];
                }
            }

            foreach ($pigs as $pig) {
                if ($this->pig_service->createPig($pig)) {
                    $this->insemination_service->addPigletRecord($pig['pig_id'], $farrowing_id);
                    $this->signalBus->emit('farm.farrowing.piglet_born', [
                        'pig_id' => $pig['pig_id'],
                        'farrowing_id' => $farrowing_id,
                    ]);

                    $pen = $this->pen_service->getPenById($pig['pen_id']);
                    $this->pen_service->updatePen($pig['pen_id'], [
                        'current_load' => ((int) $pen['current_load'] ?? 0) + 1
                    ]);
                    if (!$this->vaccination_service->isAnimalGroupVaccinationSchedulesExists($pig['pen_id'])) {
                        $this->vaccination_service->addVaccinationBatch($pen['name'], $pig['date_of_birth']);

                    }
                    $this->vaccination_service->addAnimalToVaccinationGroup($pig['pen_id'], $pig['pig_id']);
                }
            }
        }
        return $this->redirect(Url::routeByName('farm.erp.inseminations.view', ['insemination_id' => $insemination_id]));
    }

    public function feeding(Request $request, string $route_name, array $options)
    {
        return $this->renderTwig("@farm/farm/feeding.html.twig", [
            'formulas' => $this->inventoryFeed_service->getActiveFormulas(),
            'silos' => $this->inventoryFeed_service->getSilos()
        ]);
    }

    public function addSilo(Request $request, string $route_name, array $options)
    {
        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            unset($data['_csrf_token']);

            if ($this->inventoryFeed_service->createSilo($data)) {
                $this->signalBus->emit('farm.feed.silo_created', [
                    'silo' => $data,
                ]);
                Message::info("Silo added");
            }

            return $this->redirect(Url::routeByName('farm.erp.feeding'));
        }
        return $this->renderTwig("@farm/farm/add-silo.html.twig", [
            'formulas' => $this->inventoryFeed_service->getActiveFormulas(),
            'silos' => $this->inventoryFeed_service->getSilos()
        ]);
    }

    public function addFormula(Request $request, string $route_name, array $options)
    {
        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            unset($data['_csrf_token']);

            $formula = [
                'name' => $data['name'],
                'target_group' => $data['target'],
                'cost_per_ton' => $data['cost'],
                'status' => $data['status'],
                'ingredients' => []
            ];

            foreach ($data['ingredient']['ingredient_name'] ?? [] as $k => $ingredient) {
                $silo = $this->inventoryFeed_service->getSilo($ingredient);
                $formula['ingredients'][] = [
                    'ingredient_name' => $silo['contents'],
                    'percentage' => $data['ingredient']['percentage'][$k],
                    'cost_per_ton' => $data['ingredient']['cost_per_ton'][$k]
                ];
                $this->inventoryFeed_service->takeFromSilo($silo['silo_id'], $data['ingredient']['percentage'][$k]);
            }

            if ($this->inventoryFeed_service->createFeedFormula($formula)) {

                $this->signalBus->emit('farm.feed.formula_created', [
                    'formula' => $formula,
                ]);
                Message::info("Formula created");
            }
            return $this->redirect(Url::routeByName('farm.erp.feeding'));
        }
        return $this->renderTwig("@farm/farm/add-formula.html.twig", [
            'formulas' => $this->inventoryFeed_service->getActiveFormulas(),
            'silos' => $this->inventoryFeed_service->getSilos()
        ]);
    }

    public function editFormula(Request $request, string $route_name, array $options)
    {

        $formula = $this->inventoryFeed_service->getFormula($request->query->get('id'));

        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            unset($data['_csrf_token']);

            if ($this->inventoryFeed_service->updateFormula($formula['formula_id'], $data)) {
                $this->signalBus->emit('farm.feed.formula_updated', [
                    'formula_id' => $formula['formula_id'],
                    'formula' => $data,
                ]);
                Message::info("Updated");
            }
            return $this->redirect(Url::routeByName('farm.erp.feeding'));
        }


        return $this->renderTwig("@farm/farm/edit-formula.html.twig", [
            'formulas' => $this->inventoryFeed_service->getActiveFormulas(),
            'silos' => $this->inventoryFeed_service->getSilos(),
            'formula' => $formula
        ]);
    }

    public function transactions(Request $request, string $route_name, array $options)
    {
        $statics = $this->financial_service->getYearStatics();

        return $this->renderTwig("@farm/farm/transactions.html.twig", [
            'transactions' => $this->financial_service->getAllTransactions(),
            'statics' => $statics
        ]);
    }

    public function addIncomeTransaction(Request $request, string $route_name, array $options)
    {

        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            unset($data['_csrf_token']);

            $data['transaction_type'] = "Income";
            if ($this->financial_service->addTransaction($data)) {
                $this->signalBus->emit('farm.transaction.recorded', [
                    'transaction_type' => $data['transaction_type'],
                    'transaction' => $data,
                ]);
                Message::info("Added income transaction");
            }
            return $this->redirect(Url::routeByName('farm.erp.transactions'));
        }
        return $this->renderTwig("@farm/farm/income-transactions.html.twig", [

        ]);
    }

    public function duplicateIncomeTransaction(Request $request, string $route_name, array $options)
    {

        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            unset($data['_csrf_token']);

            $transaction = $this->financial_service->getTransaction($request->query->get('id'));
            $data['transaction_type'] = $transaction['transaction_type'];

            if ($this->financial_service->addTransaction($data)) {
                $this->signalBus->emit('farm.transaction.recorded', [
                    'transaction_type' => $data['transaction_type'],
                    'transaction' => $data,
                ]);
                Message::info("Added income transaction");
            }
            return $this->redirect(Url::routeByName('farm.erp.transactions'));
        }
        return $this->renderTwig("@farm/farm/duplicate-income-transactions.html.twig", [
            'transaction' => $this->financial_service->getTransaction($request->query->get('id'))
        ]);
    }

    public function editIncomeTransaction(Request $request, string $route_name, array $options)
    {
        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            unset($data['_csrf_token']);

            if ($this->financial_service->updateTransaction($request->query->get('id'), $data)) {
                $this->signalBus->emit('farm.transaction.updated', [
                    'transaction_id' => $request->query->get('id'),
                    'transaction' => $data,
                ]);
                Message::info("Added income transaction");
            }

            return $this->redirect(Url::routeByName('farm.erp.transactions'));
        }
        return $this->renderTwig("@farm/farm/edit-income-transactions.html.twig", [
            'transaction' => $this->financial_service->getTransaction($request->query->get('id'))
        ]);
    }

    public function addExpenseTransaction(Request $request, string $route_name, array $options)
    {
        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            unset($data['_csrf_token']);

            $data['transaction_type'] = "Expense";

            if ($this->financial_service->addTransaction($data)) {
                $this->signalBus->emit('farm.transaction.recorded', [
                    'transaction_type' => $data['transaction_type'],
                    'transaction' => $data,
                ]);
                Message::info("Added expense transaction");
            }

            return $this->redirect(Url::routeByName('farm.erp.transactions'));
        }
        return $this->renderTwig("@farm/farm/add-expense-transactions.html.twig", [

        ]);
    }

    public function exportLedgerTransaction(Request $request, string $route_name, array $options)
    {
        if ($request->isMethod('POST')) {
            $filter_cleared = [];
            $filters = $request->request->all();

            if (!empty($filters['start_date'])) {
                $filter_cleared['start_date'] = $filters['start_date'];
            }

            if (!empty($filters['end_date'])) {
                $filter_cleared['end_date'] = $filters['end_date'];
            }

            if (!empty($filters['type']) && strtolower($filters['type']) !== 'all') {
                $filter_cleared['transaction_type'] = $filters['type'];
            }

            if (!empty($filters['category']) && strtolower($filters['category']) !== 'all') {
                $filter_cleared['category'] = $filters['category'];
            }

            if (!empty($filters['status']) && strtolower($filters['status']) !== 'all') {
                $filter_cleared['status'] = $filters['status'];
            }

            $transactions = $this->financial_service->searchTransactions($filter_cleared);
            if ($filters['format'] === "PDF Document (.pdf)") {


                $mpdf = new \Mpdf\Mpdf(['tempDir' => __DIR__ . $_ENV['ROOT'] . '/.logs']);
                $mpdf->WriteHTML($this->renderTwig("@farm/farm/transaction-pdf.html.twig", [
                    'transactions' => $transactions,
                ])->getContent());
                $mpdf->Output();

            }
            elseif ($filters['format'] === 'CSV (.csv)'){
                $csv = SimpleCSV::export( $transactions );
                return new Response($csv,200, [
                    'Content-Type'=> "text/csv"
                ]);
            }
            elseif ($filters['format'] === "Excel Workbook (.xlsx)") {
                SimpleXLSXGen::fromArray($transactions)
                ->downloadAs("{$filter_cleared['start_date']} -  {$filter_cleared['end_date']} transactions report.xlsx");
            }

            return $this->redirect(Url::routeByName('farm.erp.transactions.export.ledger'));

        }
        return $this->renderTwig("@farm/farm/export-ledger.html.twig", [
            'transactions' => $this->financial_service->getAllTransactions()
        ]);
    }

    public function exportLedgerFilterTransaction(Request $request, string $route_name, array $options)
    {
        $filters = $request->query->all();
        $filter_cleared = [];

        if (!empty($filters['start_date'])) {
            $filter_cleared['start_date'] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $filter_cleared['end_date'] = $filters['end_date'];
        }

        if (!empty($filters['type']) && strtolower($filters['type']) !== 'all') {
            $filter_cleared['transaction_type'] = $filters['type'];
        }

        if (!empty($filters['category']) && strtolower($filters['category']) !== 'all') {
            $filter_cleared['category'] = $filters['category'];
        }

        if (!empty($filters['status']) && strtolower($filters['status']) !== 'all') {
            $filter_cleared['status'] = $filters['status'];
        }

        return $this->renderTwig("@farm/farm/export-ledger-sample-transactions.html.twig", [
            'transactions' => $this->financial_service->searchTransactions($filter_cleared)
        ]);
    }

     public function filterPigs(Request $request, string $route_name, array $options)
    {
        $filters = $request->query->all();
        $filter_cleared = [];

        if (!empty($filters['tag'])) {
            $filter_cleared['pig_id'] = $filters['tag'];
        }


        if (!empty($filters['sex']) && strtolower($filters['sex']) !== 'all sex') {
            $filter_cleared['sex'] = $filters['sex'];
        }

        if (!empty($filters['breed']) && strtolower($filters['breed']) !== 'all breed') {
            $filter_cleared['breed'] = $filters['breed'];
        }

        if (!empty($filters['location']) && strtolower($filters['location']) !== 'all farms') {
            $filter_cleared['facility_id'] = $filters['location'];
        }

        return $this->renderTwig("@farm/farm/pigs-filter.html.twig", [
            'pigs' => $this->pig_service->searchPigs($filter_cleared),
            'total_pigs' => $this->pig_service->getCount(),
        ]);
    }

    public function purchases(Request $request, string $route_name, array $options)
    {
         return $this->renderTwig("@farm/farm/purchases.html.twig", [
            'purchases' => $this->purchaseOrder_service->getPurchaseOrders(),
            'statics' => $this->purchaseOrder_service->getPurchaseOrderStatics()
        ]);
    }

    public function addPurchases(Request $request, string $route_name, array $options)
    {
        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            unset($data['_csrf_token']);

            $items = [];
            for ($i = 0; $i < count($data['items']['item_name'] ?? []); $i++) {
                $items[] = [
                    'item_name' => $data['items']['item_name'][$i],
                    'quantity'  => $data['items']['quantity'][$i],
                    'unit'      => $data['items']['unit'][$i],
                    'unit_price'=> $data['items']['unit_price'][$i]
                ];
            }

            unset($data['items']);

            $po = $this->purchaseOrder_service->addPurchaseOrder($data);
            if ($po) {
             
                $this->purchaseOrder_service->addPurchaseOrderItems($po, $items);
                $this->purchaseOrder_service->reCalculatePurchaseOrder($po);
                $this->purchaseOrder_service->addTransactions($po);
                $this->signalBus->emit('farm.purchase_order.created', [
                    'purchase_order_id' => $po,
                    'items' => $items,
                ]);
                Message::info("Added purchase order");
            }

            return $this->redirect(Url::routeByName('farm.erp.purchases'));
        }
        return $this->renderTwig("@farm/farm/add-purchase-order.html.twig", [
            'purchases' => $this->purchaseOrder_service->getPurchaseOrders(),
            'statics' => $this->purchaseOrder_service->getPurchaseOrderStatics()
        ]);
    }

    public function editPurchases(Request $request, string $route_name, array $options)
    {
        $id = $request->query->getInt('id');
        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            unset($data['_csrf_token']);

            $items = [];
            for ($i = 0; $i < count($data['items']['item_name'] ?? []); $i++) {
                $items[] = [
                    'item_name' => $data['items']['item_name'][$i],
                    'quantity'  => $data['items']['quantity'][$i],
                    'unit'      => $data['items']['unit'][$i],
                    'unit_price'=> $data['items']['unit_price'][$i]
                ];
            }

            unset($data['items']);

            $po = $this->purchaseOrder_service->updatePurchaseOrder($id,$data);
            if ($po) {
             
                $this->purchaseOrder_service->resetPurchaseOrderItems($id, $items);
                $this->purchaseOrder_service->reCalculatePurchaseOrder($id);
                $this->purchaseOrder_service->addTransactions($id);
                $this->signalBus->emit('farm.purchase_order.updated', [
                    'purchase_order_id' => $id,
                    'items' => $items,
                ]);
                Message::info("Updated purchase order");
            }

            return $this->redirect(Url::routeByName('farm.erp.purchases'));
        }
        return $this->renderTwig("@farm/farm/edit-purchase-order.html.twig", [
            'purchase'  => $this->purchaseOrder_service->getPurchaseOrder($id),
            'items'     => $this->purchaseOrder_service->getPurchaseOrderItems($id)
        ]);
    }

    public function inventory(Request $request, string $route_name, array $options)
    {
        return $this->renderTwig("@farm/farm/inventories.html.twig", [
            'inventories'  => $this->inventory_service->getAllInventory(),
        ]);
    }

    public function addInventory(Request $request, string $route_name, array $options)
    {
        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            unset($data['_csrf_token']);

            if ($this->inventory_service->addInventory($data)) {
                $this->signalBus->emit('farm.inventory.created', [
                    'inventory' => $data,
                ]);
                Message::info("Added the inventory ". $data['name']);
            }

            return $this->redirect(Url::routeByName('farm.erp.inventory'));
        }

        return $this->renderTwig("@farm/farm/add-inventory.html.twig", [
           'facilities' => $this->facility_service->getAllFacilities()
        ]);
    }

    public function editInventory(Request $request, string $route_name, array $options)
    {
         if ($request->isMethod('POST')) {
            $data = $request->request->all();
            unset($data['_csrf_token']);

            if ($this->inventory_service->updateInventory($request->query->getInt('id'),$data)) {
                $this->signalBus->emit('farm.inventory.updated', [
                    'inventory_id' => $request->query->getInt('id'),
                    'inventory' => $data,
                ]);
                Message::info("Added the inventory ". $data['name']);
            }

            return $this->redirect(Url::routeByName('farm.erp.inventory'));
        }
        return $this->renderTwig("@farm/farm/edit-inventory.html.twig", [
           'facilities' => $this->facility_service->getAllFacilities(),
           'inventory'  => $this->inventory_service->getInventory($request->query->getInt('id'))
        ]);
    }

    public function dashboard(Request $request, string $route_name, array $options)
    {
        $statics = $this->dashboard_service->dashboard();
        $user = $this->currentUser?->getUser();
        

        return $this->renderTwig("@farm/farm/dashboard.html.twig", [
           'statics'  =>  $statics,
           'display_name' => empty($user?->getFullName()) ? $user?->getUsername() : $user->getFullName(),
           'initials' => strtoupper($user?->getUsername()),
           'fullname' => empty($user?->getFullName()) ? $user?->getUsername() : $user->getFullName(),
        ]); 
    }
}
