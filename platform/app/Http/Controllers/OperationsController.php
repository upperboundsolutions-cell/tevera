<?php

namespace App\Http\Controllers;

use App\Exceptions\TraccarException;
use App\Models\Vehicle;
use App\Services\FleetInsights;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class OperationsController extends Controller
{
    public function index(Request $r)
    {
        $r->validate(['tab' => 'nullable|in:fuel,maintenance,sharing,reports']);
        $vehicles = Vehicle::visibleTo($r->user())->orderBy('name')->get();
        $ids = $vehicles->pluck('id');
        $fuel = DB::table('fuel_entries')->whereIn('vehicle_id', $ids)->orderByDesc('filled_on')->orderByDesc('odometer')->paginate(25, ['*'], 'fuel_page');
        $tasks = DB::table('maintenance_tasks')->whereIn('vehicle_id', $ids)->orderByRaw('completed_at IS NOT NULL')->orderBy('due_on')->paginate(25, ['*'], 'task_page');
        $shares = DB::table('tracking_shares')->whereIn('vehicle_id', $ids)->latest()->paginate(25, ['*'], 'share_page');
        $costs = DB::table('fuel_entries')->whereIn('vehicle_id', $ids)->selectRaw('vehicle_id, currency, SUM(cost_cents) AS cost, SUM(litres) AS litres')->groupBy('vehicle_id', 'currency')->get();
        $consumption = [];
        foreach ($vehicles as $v) {
            $entries = DB::table('fuel_entries')->where('vehicle_id', $v->id)->orderBy('odometer')->get();
            $start = null;
            $litres = 0;
            $distance = 0;
            $used = 0;
            foreach ($entries as $entry) {
                if ($start !== null) {
                    $litres += $entry->litres;
                } if ($entry->full_tank) {
                    if ($start !== null && $entry->odometer > $start) {
                        $distance += $entry->odometer - $start;
                        $used += $litres;
                    } $start = $entry->odometer;
                    $litres = 0;
                }
            }
            $consumption[$v->id] = $distance > 0 ? round($used / $distance * 100, 2) : null;
        }

        return view('fleet.operations', compact('vehicles', 'fuel', 'tasks', 'shares', 'costs', 'consumption') + ['tab' => $r->input('tab', 'fuel'), 'schedule' => DB::table('report_schedules')->where('user_id', $r->user()->id)->first()]);
    }

    public function fuel(Request $r)
    {
        $d = $r->validate(['vehicle_id' => 'required|integer', 'filled_on' => 'required|date|before_or_equal:today', 'litres' => 'required|numeric|min:0.01|max:10000', 'odometer' => 'required|numeric|min:0|max:999999999', 'cost' => 'required|numeric|min:0|max:10000000', 'currency' => 'required|in:USD,ZWG', 'full_tank' => 'nullable|boolean', 'notes' => 'nullable|string|max:500']);
        $v = Vehicle::visibleTo($r->user())->findOrFail($d['vehicle_id']);
        $d['cost_cents'] = (int) round($d['cost'] * 100);
        unset($d['cost']);
        $d['full_tank'] = $r->boolean('full_tank');
        DB::transaction(function () use ($d, $v) {
            DB::table('fuel_entries')->insert($d + ['created_at' => now(), 'updated_at' => now()]);
            Vehicle::whereKey($v->id)->where('odometer', '<', $d['odometer'])->update(['odometer' => $d['odometer']]);
        });

        return back()->with('status', 'Fuel fill-up recorded.');
    }

    public function maintenance(Request $r)
    {
        $d = $r->validate(['vehicle_id' => 'required|integer', 'title' => 'required|string|max:150', 'due_on' => 'nullable|required_without:due_odometer|date', 'due_odometer' => 'nullable|required_without:due_on|numeric|min:0|max:999999999', 'notes' => 'nullable|string|max:5000', 'document' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120']);
        Vehicle::visibleTo($r->user())->findOrFail($d['vehicle_id']);
        unset($d['document']);
        if ($r->hasFile('document')) {
            $d['document_path'] = $r->file('document')->store('maintenance', 'local');
            if (! $d['document_path']) {
                throw ValidationException::withMessages(['document' => 'The document could not be stored. Please retry.']);
            }
        }
        DB::table('maintenance_tasks')->insert($d + ['created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', 'Maintenance reminder created.');
    }

    public function complete(Request $r, int $task)
    {
        $d = $r->validate(['cost' => 'required|numeric|min:0|max:10000000', 'currency' => 'required|in:USD,ZWG']);
        $q = DB::table('maintenance_tasks')->where('id', $task)->whereIn('vehicle_id', Vehicle::visibleTo($r->user())->select('id'));
        abort_unless($q->exists(), 404);
        $q->whereNull('completed_at')->update(['cost_cents' => (int) round($d['cost'] * 100), 'currency' => $d['currency'], 'completed_at' => now(), 'updated_at' => now()]);

        return back()->with('status', 'Service recorded as completed.');
    }

    public function document(Request $r, int $task)
    {
        $task = DB::table('maintenance_tasks')->where('id', $task)->whereIn('vehicle_id', Vehicle::visibleTo($r->user())->select('id'))->first();
        abort_unless($task && $task->document_path, 404);

        return Storage::disk('local')->download($task->document_path);
    }

    public function snapshot(Request $r, FleetInsights $insights)
    {
        try {
            return response()->json($insights->snapshot($r->user()));
        } catch (TraccarException) {
            return response()->json(['message' => 'Tracking unavailable. Maintenance records remain available.'], 503);
        }
    }

    public function report(Request $r, FleetInsights $insights)
    {
        $d = $r->validate(['vehicle_id' => 'required|integer', 'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from', 'format' => 'nullable|in:html,csv']);
        $v = Vehicle::visibleTo($r->user())->with('driver')->findOrFail($d['vehicle_id']);
        $from = CarbonImmutable::parse($d['from'], 'UTC')->startOfDay();
        $to = CarbonImmutable::parse($d['to'], 'UTC')->endOfDay()->min(CarbonImmutable::now('UTC'));
        abort_if($from->gt($to) || $from->diffInDays($to) > 7, 422, 'Choose up to seven days ending today or earlier.');
        try {
            $report = $insights->report($v, $from, $to);
        } catch (TraccarException) {
            return back()->withErrors(['report' => 'Tracking data unavailable. Please retry.']);
        }
        if ($r->input('format') === 'csv') {
            return response()->streamDownload(function () use ($report) {
                $f = fopen('php://output', 'w');
                fputcsv($f, array_keys($report), ',', '"', '');
                fputcsv($f, array_map(fn ($x) => is_string($x) && preg_match('/^[=+@\-\t\r]/', $x) ? "'".$x : $x, array_values($report)), ',', '"', '');
                fclose($f);
            }, 'tevera-report.csv', ['Content-Type' => 'text/csv']);
        }

        return view('fleet.report', compact('report', 'from', 'to', 'v'));
    }

    public function schedule(Request $r)
    {
        $d = $r->validate(['frequency' => 'required|in:off,daily,weekly']);
        $q = DB::table('report_schedules')->where('user_id', $r->user()->id);
        if ($d['frequency'] === 'off') {
            $q->delete();
        } else {
            DB::table('report_schedules')->updateOrInsert(['user_id' => $r->user()->id], ['frequency' => $d['frequency'], 'next_run_at' => now()->addDay()->startOfDay()->addHours(6), 'created_at' => now(), 'updated_at' => now()]);
        }

        return back()->with('status','Report schedule saved. Reports go to your account email.');
    }
}
