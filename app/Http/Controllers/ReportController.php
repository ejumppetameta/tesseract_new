<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * Display a paginated list of evaluation reports.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        // Retrieve reports ordered by created_at descending and paginate 10 per page.
        $reports = DB::table('model_evaluations')->orderBy('created_at', 'desc')->paginate(10);

        return view('reports.index', compact('reports'));
    }

    /**
     * Display the specified evaluation report.
     *
     * @param  int  $id
     * @return \Illuminate\View\View
     */
    public function show($id)
    {
        $report = DB::table('model_evaluations')->find($id);
        if (!$report) {
            abort(404, 'Report not found');
        }
        return view('reports.show', compact('report'));
    }
}
