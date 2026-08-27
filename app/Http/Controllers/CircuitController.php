<?php

namespace App\Http\Controllers;

use App\Events\CircuitChanged;
use App\Http\Requests\JoinCircuitRequest;
use App\Http\Requests\StoreCircuitRequest;
use App\Http\Requests\StoreComponentRequest;
use App\Http\Requests\StoreWireRequest;
use App\Http\Requests\UpdateComponentRequest;
use App\Models\Circuit;
use App\Models\CircuitComponent;
use App\Models\CircuitWire;
use App\Models\SessionUser;
use App\Services\CircuitGraphService;
use App\Services\CircuitService;

class CircuitController extends Controller
{
    public function __construct(
        private readonly CircuitService $circuitService,
        private readonly CircuitGraphService $graphService,
    ) {}

    public function index()
    {
        return Circuit::withCount('components')->latest()->get();
    }

    public function store(StoreCircuitRequest $request)
    {
        return $this->circuitService->createCircuit($request->validated());
    }

    public function show(Circuit $circuit)
    {
        return $this->circuitService->getCircuitPayload($circuit);
    }

    public function join(JoinCircuitRequest $request, Circuit $circuit)
    {
        $data = $request->validated();
        $result = SessionUser::joinCircuit($circuit, $data['name'], $data['session_uuid'] ?? null);

        broadcast(new CircuitChanged($circuit->id, 'participant.joined'))->toOthers();

        return response()->json(
            $this->circuitService->getCircuitPayload($circuit->fresh()) + [
                'session_uuid' => $result['session_uuid'],
                'display_name' => $result['display_name'],
            ]
        );
    }

    public function component(StoreComponentRequest $request, Circuit $circuit)
    {
        return $this->graphService->createComponent($circuit, $request->validated());
    }

    public function updateComponent(UpdateComponentRequest $request, CircuitComponent $component)
    {
        return $this->graphService->updateComponent($component, $request->validated());
    }

    public function removeComponent(CircuitComponent $component)
    {
        $this->graphService->deleteComponent($component);

        return response()->noContent();
    }

    public function wire(StoreWireRequest $request, Circuit $circuit)
    {
        return $this->graphService->createWire($circuit, $request->validated());
    }

    public function removeWire(CircuitWire $wire)
    {
        $this->graphService->deleteWire($wire);

        return response()->noContent();
    }

    public function clear(Circuit $circuit)
    {
        return response()->json($this->circuitService->clearCircuit($circuit));
    }

    public function loadDemo(Circuit $circuit)
    {
        return response()->json($this->circuitService->loadDemoCircuit($circuit));
    }
}
