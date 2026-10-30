<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CategoryBulkAction;
use App\Http\Requests\Categories\BulkCategoryRequest;
use App\Http\Requests\Categories\CategoryAvailabilityRequest;
use App\Http\Requests\Categories\CategoryIndexRequest;
use App\Http\Requests\Categories\StoreCategoryRequest;
use App\Http\Requests\Categories\UpdateCategoryRequest;
use App\Models\Category;
use App\Services\Categories\CategoryService;
use HasinHayder\TyroDashboard\Http\Controllers\BaseController;
use HasinHayder\TyroDashboard\Support\DashboardRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CategoryController extends BaseController
{
    public function index(CategoryIndexRequest $request, CategoryService $categories): View
    {
        $items = $categories->paginate($request->user(), $request->validated());

        return view('admin.categories.index', $this->getViewData([
            'dashboardRoute' => DashboardRoute::class,
            'categories' => $items->withQueryString(),
            'filters' => array_merge(['status' => 'active', 'search' => ''], $request->validated()),
        ]));
    }

    public function create(): View
    {
        return view('admin.categories.create', $this->getViewData(['dashboardRoute' => DashboardRoute::class]));
    }

    public function store(StoreCategoryRequest $request, CategoryService $categories): RedirectResponse
    {
        $data = $request->validated();
        $categories->create(
            $request->user(),
            (string) $data['name'],
            (string) $data['slug'],
            isset($data['description']) ? (string) $data['description'] : null,
        );

        return to_route('dashboard.categories.index')->with('success', 'Category created successfully.');
    }

    public function edit(Category $category): View
    {
        return view('admin.categories.edit', $this->getViewData([
            'category' => $category,
            'dashboardRoute' => DashboardRoute::class,
        ]));
    }

    public function update(UpdateCategoryRequest $request, Category $category, CategoryService $categories): RedirectResponse
    {
        $data = $request->validated();
        $categories->update(
            $category,
            $request->user(),
            (string) $data['name'],
            isset($data['description']) ? (string) $data['description'] : null,
        );

        return to_route('dashboard.categories.index')->with('success', 'Category updated successfully.');
    }

    public function availability(CategoryAvailabilityRequest $request, Category $category, CategoryService $categories): RedirectResponse
    {
        $categories->setActive($category, $request->boolean('is_active'), $request->user());

        return back()->with('success', $request->boolean('is_active') ? 'Category enabled.' : 'Category disabled.');
    }

    public function archive(Category $category, CategoryService $categories): RedirectResponse
    {
        $categories->archive($category, request()->user());

        return to_route('dashboard.categories.index', ['status' => 'archived'])
            ->with('success', 'Category archived. Existing tickets keep their category reference.');
    }

    public function restore(int $category, CategoryService $categories): RedirectResponse
    {
        $categories->restore($category, request()->user());

        return to_route('dashboard.categories.index', ['status' => 'inactive'])
            ->with('success', 'Category restored as disabled. Enable it when it is ready for new tickets.');
    }

    public function bulk(BulkCategoryRequest $request, CategoryService $categories): RedirectResponse
    {
        $result = $categories->bulk(
            $request->user(),
            CategoryBulkAction::from($request->validated('action')),
            array_values(array_map('intval', $request->validated('selected_ids'))),
        );

        $redirect = back()->with('success', "Bulk action completed for {$result['succeeded']} categories.");

        if ($result['failed'] !== []) {
            $redirect->with('bulk_failures', $result['failed']);
        }

        return $redirect;
    }
}
