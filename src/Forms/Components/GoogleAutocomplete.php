<?php

namespace Tapp\FilamentGoogleAutocomplete\Forms\Components;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Schemas\Components\Component;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Concerns;
use Filament\Schemas\Components\Concerns\EntanglesStateWithSingularRelationship;
use Filament\Schemas\Components\Contracts\CanEntangleWithSingularRelationships;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Tapp\FilamentGoogleAutocomplete\Concerns\CanFormatGoogleParams;
use Tapp\FilamentGoogleAutocomplete\Concerns\HasGooglePlaceApi;

// Original places API class
// places API new class

class GoogleAutocomplete extends Field implements CanEntangleWithSingularRelationships
{
    use EntanglesStateWithSingularRelationship;
    use CanFormatGoogleParams;
    use HasGooglePlaceApi;

    /**
     * @var view-string
     */
    protected string $view = 'filament-schemas::components.grid';

    protected bool|Closure $isRequired = false;

    protected array $params = [];

    public ?array $withFields = [];

    protected string|Closure $autocompleteFieldColumnSpan = 'full';

    protected int|Closure $autocompleteSearchDebounce = 750; // 3/4 second

    protected array|Closure $addressFieldsColumns = [];

    protected string|Closure|null $autocompleteLabel = null;

    protected string|Closure|null $autocompleteName = null;

    protected string|Closure|null $autocompletePlaceholder = null;

    public static function make(?string $name = null): static
    {
        $static = app(static::class, ['name' => $name]);
        $static->configure();
        $static->columnSpanFull();
        $static->name = $name;

        return $static;
    }

    public function getChildSchema($key = null): ?Schema
    {
        $components = [];

        $components[] = Select::make($this->getAutocompleteName())
            ->label($this->getAutocompleteLabel())
            ->native(false)
            ->dehydrated(false)
            ->allowHtml()
            ->live()
            ->validatedWhenNotDehydrated(false)
            ->placeholder($this->getAutocompletePlaceholder())
            ->searchDebounce($this->getAutocompleteSearchDebounce())
            ->searchingMessage(__('filament-google-autocomplete-field::filament-google-autocomplete-field.autocomplete.searching.message'))
            ->searchPrompt(__('filament-google-autocomplete-field::filament-google-autocomplete-field.autocomplete.search.prompt'))
            ->searchable()
            ->hint(new HtmlString(Blade::render('<x-filament::loading-indicator class="h5 w-5" wire:loading wire:target="data.google_autocomplete_' . $this->getAutocompleteName() . '" />')))
            ->columnSpan($this->getAutocompleteFieldColumnSpan())
            ->getSearchResultsUsing(function (string $search, Set $set): array {
                $set($this->getAutocompleteName(), null);
                $response = $this->getPlaceAutocomplete($search);

                $result = $response->collect();

                return $this->getPlaceAutocompleteResult($result);
            })
            ->afterStateUpdated(function (?string $state, Set $set) {
                if ($state === null) {
                    foreach ($this->getWithFields() as $field) {
                        $set($field->getName(), null);
                    }

                    return;
                }

                $data = $this->getPlace($state);

                $googleFields = $this->getFormattedApiResults($data);

                foreach ($this->getWithFields() as $field) {
                    $fieldExtraAttributes = $field->getExtraInputAttributes();

                    $googleFieldName = count($fieldExtraAttributes) > 0 && isset($fieldExtraAttributes['data-google-field']) ? $fieldExtraAttributes['data-google-field'] : $field->getName();

                    $googleFieldValue = count($fieldExtraAttributes) > 0 && isset($fieldExtraAttributes['data-google-value']) ? $fieldExtraAttributes['data-google-value'] : 'long_name';

                    // if the field contains combined values
                    if (str_contains($googleFieldName, '{')) {
                        $value = $this->replaceFieldPlaceholders($googleFieldName, $googleFields, $googleFieldValue);
                    } else {
                        // bc: Fixes issue with Carson City, NV.  No administrative_area_level_2 provided in search result.
                        $value = '';
                        if (isset($googleFields[$googleFieldName][$googleFieldValue])) {
                            $value = $googleFields[$googleFieldName][$googleFieldValue] ?: '';
                        }
                    }

                    $set($field->getName(), $value);
                    $field->callAfterStateUpdated(FALSE);
                }
            });

        $addressData = Arr::map(
            $this->getWithFields(),
            function (Component $component) {
                return $component;
            }
        );

        $allComponents = array_merge($components, $addressData);

        $key ??= 'default';

        return $this->configureChildSchema(
            $this->makeChildSchema($key)
                ->components([
                    Grid::make($this->getAddressFieldsColumns())
                        ->schema(
                            $allComponents
                        ),
                ]),
            $key,
        );
    }

    protected function replaceFieldPlaceholders($googleField, $googleFields, $googleFieldValue)
    {
        preg_match_all('/{(.*?)}/', $googleField, $matches);

        foreach ($matches[1] as $item) {
            $valueToReplace = isset($googleFields[$item][$googleFieldValue]) ? $googleFields[$item][$googleFieldValue] : '';

            $googleField = str_ireplace('{' . $item . '}', $valueToReplace, $googleField);
        }

        return $googleField;
    }

    protected function getPlaceAutocompleteResult($result)
    {
        if ($this->placesApiNew) {
            if (isset($result['suggestions']) && !empty($result['suggestions'])) {
                $searchResults = collect($result['suggestions'])->mapWithKeys(function (array $item, int $key) {
                    return [$item['placePrediction']['placeId'] => $item['placePrediction']['text']['text']];
                });

                return $searchResults->toArray();
            }
        } else {
            if (!empty($result['predictions'])) {
                $searchResults = collect($result['predictions'])->mapWithKeys(function (array $item, int $key) {
                    return [$item['place_id'] => $item['description']];
                });

                return $searchResults->toArray();
            }
        }

        return [];
    }

    public function withFields(null|array|string|Closure $fields): static
    {
        $this->withFields = $fields;

        return $this;
    }

    public function getWithFields(): ?array
    {
        if (empty($this->withFields)) {
            return [
                TextInput::make('address')
                    ->extraInputAttributes([
                        'data-google-field' => '{street_number} {route}, {sublocality_level_1}',
                    ]),
                TextInput::make('city')
                    ->extraInputAttributes([
                        'data-google-field' => 'locality',
                    ]),
                TextInput::make('administrative_area_level_1')
                    ->label('State'),
                TextInput::make('country'),
                TextInput::make('zip')
                    ->extraInputAttributes([
                        'data-google-field' => 'postal_code',
                    ]),
                TextInput::make('latitude'),
                TextInput::make('longitude'),
            ];
        }

        return $this->evaluate($this->withFields);
    }

    public function autocompleteFieldColumnSpan(string|Closure $autocompleteFieldColumnSpan = 'full'): static
    {
        $this->autocompleteFieldColumnSpan = $autocompleteFieldColumnSpan;

        $this->params['autocompleteFieldColumnSpan'] = $autocompleteFieldColumnSpan;

        return $this;
    }

    public function getAutocompleteFieldColumnSpan(): ?string
    {
        return $this->evaluate($this->autocompleteFieldColumnSpan);
    }

    public function addressFieldsColumns(null|array|string|Closure $addressFieldsColumns): static
    {
        $this->addressFieldsColumns = $addressFieldsColumns;

        return $this;
    }

    public function getAddressFieldsColumns(): ?array
    {
        if (empty($this->addressFieldsColumns)) {
            return [
                'default' => 1,
                'sm' => 2,
            ];
        }

        return $this->evaluate($this->addressFieldsColumns);
    }

    public function autocompleteSearchDebounce(int|Closure $autocompleteSearchDebounce = 2000): static
    {
        $this->autocompleteSearchDebounce = $autocompleteSearchDebounce;

        $this->params['autocompleteSearchDebounce'] = $autocompleteSearchDebounce;

        return $this;
    }

    public function getAutocompleteSearchDebounce(): ?int
    {
        return $this->evaluate($this->autocompleteSearchDebounce);
    }

    public function autocompleteLabel(string|Closure|null $label): static
    {
        $this->autocompleteLabel = $label;

        return $this;
    }

    protected function getAutocompleteLabel(): string
    {
        return $this->evaluate($this->autocompleteLabel) ?? $this->getLabel();
    }

    public function autocompleteName(string|Closure|null $name): static
    {
        $this->autocompleteName = $name;

        return $this;
    }

    protected function getAutocompleteName(): string
    {
        return $this->evaluate($this->autocompleteName) ?? 'google_autocomplete_' . $this->name;
    }

    public function autocompletePlaceholder(string|Closure|null $placeholder): static
    {
        $this->autocompletePlaceholder = $placeholder;

        return $this;
    }

    protected function getAutocompletePlaceholder(): string
    {
        return $this->evaluate($this->autocompletePlaceholder) ?? __('Select an option');
    }
}
