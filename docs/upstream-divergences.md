# Divergencias con upstream

Cambios hechos **en el host** (`app/`, `config/`, `tests/`) que no vienen de
Relaticle y que hay que revisar en cada rebase sobre `upstream/main`.

La rama `srcrm-seams` se rebasea sobre upstream (ver `docs/deploy.md`), así que
cada entrada de aquí es un punto de conflicto potencial. Para cada una: **por qué
está**, **cómo saber si sigue haciendo falta** y **cómo deshacerla**.

La norma del fork es no tocar el host y resolverlo todo desde el addon
`srjingles/sr-crm`. Lo que aparezca en este documento es una excepción
deliberada, no un descuido.

**Qué entra aquí y qué no.** Solo los cambios sobre ficheros **que son de upstream**
y que existen para rodear un defecto suyo — o sea, lo que algún día habrá que
**deshacer** cuando upstream lo arregle. No entran los ficheros nuevos del fork
(`app/Models/UserPreference.php`, `app/Filament/Concerns/PersistsTableColumns.php`,
`app/Support/CustomFields/DynamicChoices.php`…), que no generan conflicto ni hay que
revertir, ni los seams de extensibilidad salvo cuando comparten fichero con una
entrada de aquí.

Para ver el conjunto completo de divergencias en cualquier momento:

```bash
git diff --name-only upstream/main HEAD -- app/ config/ bootstrap/
```

---

## 1. Contexto de tenant al sembrar los campos de un equipo nuevo

**Fecha:** 2026-07-30
**Ficheros:** `app/Listeners/CreateTeamCustomFields.php`,
`tests/Feature/Onboarding/CreateTeamOnboardingTest.php`,
`tests/Feature/Filament/App/Exports/*ExporterTest.php`

### Qué se cambió

`CreateTeamCustomFields::handle()` envuelve el sembrado de campos en
`TenantContextService::withTenant($team->id, ...)`. Antes solo llamaba a
`$this->migrator->setTenantId($team->id)`.

### Por qué

`setTenantId()` informa **al migrador**, pero las consultas Eloquent que este hace
por debajo se scopan por otra cosa: el contexto ambiente de
`TenantContextService`. Los dos conceptos de tenant divergían.

Con la feature `SYSTEM_SECTIONS` del paquete de custom fields activa —la enciende
el addon para agrupar los formularios; ver
`SrCrmServiceProvider::registerCustomFieldSections()`— el migrador hace un
`updateOrCreate` sobre `custom_field_sections`. Si al crear un equipo el contexto
ambiente apunta a **otro** equipo (el caso normal: un usuario que ya está dentro
del equipo A y crea el equipo B), esa búsqueda mira en el tenant equivocado, no
encuentra la sección, la inserta y choca contra
`custom_field_sections_entity_type_code_tenant_id_unique`.

**Síntoma:** crear un segundo equipo devuelve un 500.

Es además lo que exige la propia regla de custom fields de `CLAUDE.md`: todo
camino de escritura que no sea una petición del panel ni pase por
`SetApiTeamContext` debe fijar el contexto de tenant. Este listener no lo hacía,
así que el arreglo se sostiene por sí mismo aunque algún día se apaguen las
secciones.

### Cambio asociado en los tests

Cinco tests de exportadores hacen `Event::fake()->except([...])`. El addon asigna
la sección en el `creating` del modelo, y el fake se lo tragaba: el campo nacía
sin sección y quedaba invisible. Se añadió
`'eloquent.creating: App\Models\CustomField'` a la lista de excepciones.

### Cómo saber si sigue haciendo falta

Existe test de regresión: **`it('crea un segundo equipo aunque el contexto de
tenant apunte a otro')`** en `tests/Feature/Onboarding/CreateTeamOnboardingTest.php`.
Verificado que falla sin el arreglo, con el error de clave duplicada exacto.

Tras rebasear, si upstream ha tocado el listener:

1. Comprobar si upstream ya fija el contexto de tenant por su cuenta (buscar
   `TenantContextService` o `withTenant` en el listener). Si lo hace, **quitar
   nuestra envoltura** y dejar la suya.
2. Correr ese test. Si pasa sin nuestro cambio, la divergencia sobra: bórrala de
   aquí y del código.

### Cómo deshacerla

Quitar la llamada a `withTenant()` dejando el `DB::transaction(...)` desnudo, y
retirar el import de `TenantContextService`. Los `except` de los tests de
exportadores solo estorban si además se apagan las secciones
(`SRCRM_CUSTOM_FIELDS_SECTIONS=false`).

### Alternativa descartada

Resolverlo desde el addon adelantándose al listener del host. No vale un observer
del modelo `Team`: `TeamCreated` se dispara por `$dispatchesEvents['created']`, que
Eloquent resuelve **antes** que los observers. Quedaba registrar un listener del
addon confiando en el orden de registro, que es frágil y silencioso si se rompe.
Se prefirió arreglar el host, que es donde está el defecto de verdad, y que además
merece ir a upstream.

---

## 2. Evento seam `TeamCustomFieldsCreated`

**Ficheros:** `app/Listeners/CreateTeamCustomFields.php`, `app/Events/TeamCustomFieldsCreated.php`

No es un rodeo a un defecto, sino un **seam**: el listener emite el evento al
terminar de sembrar los campos base y el onboarding, y el addon se engancha ahí
(`SrJingles\SrCrm\Listeners\SeedTeamCustomFields`) con el orden garantizado —
después de los campos de Relaticle, nunca antes.

Se anota aquí porque **comparte fichero con la entrada 1**: quien resuelva un
conflicto en `CreateTeamCustomFields` tiene que conservar las dos cosas, la
envoltura `withTenant()` y el `event(new TeamCustomFieldsCreated($team))` del
final. Si desaparece el evento, el addon deja de sembrar sus campos en los equipos
nuevos y cae al fallback de `TeamCreated` (ver
`SrCrmServiceProvider::bootBusinessLogic()`), que no garantiza el orden.

No hay que deshacerlo nunca: se retira solo si upstream ofrece un punto de
extensión equivalente.
