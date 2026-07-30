<?php

declare(strict_types=1);

use Relaticle\CustomFields\Enums\CustomFieldSectionType;
use Relaticle\CustomFields\Filament\Integration\Factories\SectionComponentFactory;
use Relaticle\CustomFields\Models\CustomFieldSection;
use SrJingles\SrCrm\CustomFields\CollapsibleSectionComponentFactory;
use SrJingles\SrCrm\CustomFields\SrCrmFieldBlueprint;

/*
 | Agrupación de los formularios en secciones (addon srjingles/sr-crm).
 |
 | El plegado no lo ofrece el paquete de custom fields: lo añade el addon envolviendo
 | SectionComponentFactory con un decorador, y lo enchufa con un bind del contenedor.
 | Ese bind es duck-typing —la factoría original es `final readonly`, así que el
 | decorador NO hereda de ella— y aguanta solo mientras el punto de uso la resuelva con
 | app() y llame a create() sobre una variable sin tipar. Si el paquete pasara a
 | inyectarla por constructor tipado, el bind dejaría de valer EN SILENCIO: las
 | secciones se seguirían pintando, simplemente ya no se plegarían.
 */

it('resuelve la factoría de secciones al decorador del addon', function (): void {
    expect(resolve(SectionComponentFactory::class))
        ->toBeInstanceOf(CollapsibleSectionComponentFactory::class);
});

it('pliega las secciones listadas en la config y deja abiertas las demás', function (): void {
    config()->set('srcrm.custom_fields.collapsed_sections', ['address']);

    $plegada = makeSection('address');
    $abierta = makeSection('identification');

    $factory = resolve(SectionComponentFactory::class);

    expect($factory->create($plegada)->isCollapsed())->toBeTrue()
        ->and($factory->create($abierta)->isCollapsed())->toBeFalse();
});

/*
 | El fallo que motivó este test: las cuatro secciones de Oportunidad no se plegaban
 | porque nunca se añadieron sus códigos a la lista. Un código mal escrito da el mismo
 | resultado —no pliega nada— y tampoco avisa: no hay error, solo una sección abierta
 | que debería estar cerrada. Aquí se contrasta la lista contra los códigos que declara
 | el blueprint, que es la fuente de verdad de qué secciones existen.
 */
it('no tiene códigos inventados en la lista de secciones plegadas', function (): void {
    $declarados = collect(SrCrmFieldBlueprint::ensure(config('srcrm.models')))
        ->map(fn (object $field): ?string => $field->data->section?->code)
        ->filter()
        ->unique()
        ->all();

    $configurados = (array) config('srcrm.custom_fields.collapsed_sections', []);

    expect(array_values(array_diff($configurados, $declarados)))->toBe([]);
});

function makeSection(string $code): CustomFieldSection
{
    $section = new CustomFieldSection;

    $section->forceFill([
        'code' => $code,
        'name' => ucfirst($code),
        'type' => CustomFieldSectionType::SECTION,
        'entity_type' => 'company',
    ]);

    return $section;
}
