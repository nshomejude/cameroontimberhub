<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\SearchService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends Controller
{
    public function __construct(private readonly SearchService $search) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        return view('public.search', array_merge(
            ['q' => $q],
            $this->search->searchAll($q),
        ));
    }
}
