<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SpeciesIndexRequest;
use App\Http\Resources\Api\V1\SpeciesDetailResource;
use App\Http\Resources\Api\V1\SpeciesResource;
use App\Services\SpeciesDirectoryService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The public species directory, read through SpeciesDirectoryService so the
 * `published` gate and every facet definition are shared with the web.
 */
class SpeciesController extends Controller
{
    public function __construct(private readonly SpeciesDirectoryService $directory) {}

    public function index(SpeciesIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->filters();
        $context = ['q' => $filters['q'], 'region' => $filters['region']];

        $species = $this->directory->search($filters, $request->perPage());

        return SpeciesResource::collection($species)->additional([
            'meta' => [
                'facets' => [
                    'categories' => $this->directory->categoryFacets($context, $filters['categories']),
                    'properties' => $this->directory->facets('properties', $context, $filters['properties']),
                    'applications' => $this->directory->facets('applications', $context, $filters['applications']),
                    'regions' => $this->directory->regionFacets(),
                    'in_stock' => $this->directory->inStockFacet($context),
                ],
                'sort_options' => SpeciesDirectoryService::sortOptions(),
            ],
        ]);
    }

    public function show(string $slug): SpeciesDetailResource
    {
        return new SpeciesDetailResource(
            $this->directory->base()->where('slug', $slug)->firstOrFail(),
        );
    }
}
