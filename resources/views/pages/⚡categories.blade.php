<?php

use App\Models\Category;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Categories')] class extends Component {
    public string $categoryName = '';

    public string $categoryColor = '#64748B';

    public ?int $editingCategoryId = null;

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return Category::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function saveCategory(): void
    {
        $validated = $this->validate([
            'categoryName' => [
                'required',
                'string',
                'max:120',
                Rule::unique('categories', 'name')
                    ->where(fn ($query) => $query->where('user_id', Auth::id()))
                    ->ignore($this->editingCategoryId),
            ],
            'categoryColor' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $category = $this->editingCategoryId
            ? Category::query()->findOrFail($this->editingCategoryId)
            : new Category;

        $category->fill([
            'name' => $validated['categoryName'],
            'color' => strtoupper($validated['categoryColor']),
        ]);

        if (! $this->editingCategoryId) {
            $category->sort_order = ((int) Category::query()->max('sort_order')) + 1;
        }

        $category->save();

        $this->resetForm();
        unset($this->categories);

        Flux::toast(variant: 'success', text: __('Category saved.'));
    }

    public function editCategory(int $categoryId): void
    {
        $category = Category::query()->findOrFail($categoryId);

        $this->editingCategoryId = $category->id;
        $this->categoryName = $category->name;
        $this->categoryColor = $category->color;
    }

    public function deleteCategory(int $categoryId): void
    {
        Category::query()->findOrFail($categoryId)->delete();

        unset($this->categories);

        Flux::toast(variant: 'success', text: __('Category deleted.'));
    }

    public function moveCategory(int $categoryId, string $direction): void
    {
        $category = Category::query()->findOrFail($categoryId);

        $neighbor = Category::query()
            ->when(
                $direction === 'up',
                fn ($query) => $query
                    ->where(function ($query) use ($category): void {
                        $query
                            ->where('sort_order', '<', $category->sort_order)
                            ->orWhere(function ($query) use ($category): void {
                                $query->where('sort_order', $category->sort_order)->where('id', '<', $category->id);
                            });
                    })
                    ->orderByDesc('sort_order')
                    ->orderByDesc('id'),
                fn ($query) => $query
                    ->where(function ($query) use ($category): void {
                        $query
                            ->where('sort_order', '>', $category->sort_order)
                            ->orWhere(function ($query) use ($category): void {
                                $query->where('sort_order', $category->sort_order)->where('id', '>', $category->id);
                            });
                    })
                    ->orderBy('sort_order')
                    ->orderBy('id'),
            )
            ->first();

        if (! $neighbor) {
            return;
        }

        DB::transaction(function () use ($category, $neighbor): void {
            $categoryOrder = $category->sort_order;
            $category->update(['sort_order' => $neighbor->sort_order]);
            $neighbor->update(['sort_order' => $categoryOrder]);
        });

        unset($this->categories);
    }

    public function resetForm(): void
    {
        $this->reset('categoryName', 'editingCategoryId');
        $this->categoryColor = '#64748B';
        $this->resetValidation();
    }
}; ?>

<section class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-8 p-6 lg:p-10">
    <header class="flex flex-col gap-2 border-b border-zinc-200 pb-6 dark:border-zinc-700">
        <flux:heading size="xl">{{ __('Categories') }}</flux:heading>
        <flux:subheading>{{ __('Organize expenses and income with names and colors.') }}</flux:subheading>
    </header>

    <section class="space-y-5">
        <div class="flex items-center justify-between gap-4">
            <flux:heading size="lg">{{ $editingCategoryId ? __('Edit category') : __('New category') }}</flux:heading>
            @if ($editingCategoryId)
                <flux:button variant="ghost" size="sm" wire:click="resetForm">{{ __('Cancel') }}</flux:button>
            @endif
        </div>

        <form wire:submit="saveCategory" class="flex flex-col gap-4 sm:flex-row sm:items-end">
            <flux:input wire:model="categoryName" :label="__('Name')" placeholder="{{ __('Category name') }}" />
            <flux:input wire:model="categoryColor" :label="__('Color')" type="color" />
            <flux:button type="submit" variant="primary">{{ $editingCategoryId ? __('Save changes') : __('Add category') }}</flux:button>
        </form>
    </section>

    <section class="space-y-4">
        <flux:heading size="lg">{{ __('Your categories') }}</flux:heading>

        <div class="divide-y divide-zinc-200 border-y border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @forelse ($this->categories as $category)
                <div wire:key="category-{{ $category->id }}" class="flex items-center gap-4 py-3">
                    <span class="size-4 shrink-0 rounded-full border border-black/10" style="background-color: {{ $category->color }}" aria-hidden="true"></span>
                    <flux:text class="min-w-0 flex-1 truncate font-medium">{{ $category->name }}</flux:text>
                    <div class="flex shrink-0 gap-1">
                        <flux:button variant="ghost" size="sm" icon="chevron-up" aria-label="{{ __('Move category up') }}" wire:click="moveCategory({{ $category->id }}, 'up')" />
                        <flux:button variant="ghost" size="sm" icon="chevron-down" aria-label="{{ __('Move category down') }}" wire:click="moveCategory({{ $category->id }}, 'down')" />
                        <flux:button variant="ghost" size="sm" icon="pencil" aria-label="{{ __('Edit category') }}" wire:click="editCategory({{ $category->id }})" />
                        <flux:button variant="ghost" size="sm" icon="trash" aria-label="{{ __('Delete category') }}" wire:click="deleteCategory({{ $category->id }})" />
                    </div>
                </div>
            @empty
                <flux:text class="py-4">{{ __('No categories yet.') }}</flux:text>
            @endforelse
        </div>
    </section>
</section>
