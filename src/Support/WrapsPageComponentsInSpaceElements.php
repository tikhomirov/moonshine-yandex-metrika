<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Support;

use MoonShine\Contracts\UI\ComponentContract;
use MoonShine\UI\Components\Layout\Div;

/**
 * Оборачивает список компонентов страницы в Div.space-elements,
 * чтобы соседние блоки получали стандартный вертикальный ритм MoonShine.
 *
 * Можно удалить, если в ядре MoonShine появится opt-in на корне контента
 * (см. https://github.com/moonshine-software/moonshine/issues/2108).
 */
trait WrapsPageComponentsInSpaceElements
{
    /**
     * @param  iterable<int, ComponentContract>  $components
     * @return list<ComponentContract>
     */
    protected function wrapInSpaceElements(iterable $components, string ...$classes): array
    {
        $items = [...$components];

        if ($items === []) {
            return [];
        }

        return [
            Div::make($items)->class(array_values(array_unique(['space-elements', ...$classes]))),
        ];
    }
}
