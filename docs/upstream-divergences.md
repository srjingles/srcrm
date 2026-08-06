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

---

## 3. Namespace de vistas de `packages/Documentation` fuera del guard de la feature

**Fecha:** 2026-07-30
**Ficheros:** `packages/Documentation/src/DocumentationServiceProvider.php`

### Qué se cambió

`boot()` registra vistas, componentes Blade y publishing **siempre**, y deja bajo
`Feature::active(Documentation::class)` únicamente el registro de rutas. Antes el
guard cortaba en seco las cuatro cosas.

### Por qué

La feature apaga la *sección* de documentación, o sea sus rutas. Condicionar lo
demás tenía dos efectos colaterales:

1. `php artisan vendor:publish --tag=documentation-config` no hacía nada con la
   feature apagada, en silencio.
2. El namespace `documentation::` quedaba sin declarar, así que el análisis
   estático no podía resolver ningún view-string del paquete. Larastan valida
   `view-string` llamando a `view()->exists()` sobre la app arrancada
   (`vendor/larastan/larastan/src/Types/ViewStringType.php`), y en ese arranque la
   feature evalúa a `false`. De ahí **4 errores `argument.type`** en
   `Components/Card.php` y `Http/Controllers/DocumentationController.php`.

Vistas y componentes son inertes sin rutas que los usen, así que registrarlos
siempre no enciende nada.

### Cómo saber si sigue haciendo falta

```bash
rm -rf /tmp/phpstan && vendor/bin/phpstan analyse
```

El borrado de `/tmp/phpstan` no es opcional: `clear-result-cache` **no** vacía la
caché de contenedor de larastan, y sin borrarla el análisis sigue devolviendo los
4 errores aunque el arreglo esté puesto. Comprobación directa del mecanismo:

```bash
php -r 'require "vendor/autoload.php"; require "vendor/larastan/larastan/bootstrap.php";
        var_dump(view()->exists("documentation::index"));'
```

`true` con el arreglo, `false` sin él.

### Cómo deshacerla

Devolver el guard al principio de `boot()`, envolviendo las cuatro llamadas.

---

## 4. Seam `config('teams.extra_reserved_slugs')`

**Fecha:** 2026-07-30 (reescrita 2026-08-04 tras sluggable v4)
**Ficheros:** `app/Models/Team.php`, `app/Rules/ValidTeamSlug.php`,
`tests/Feature/Teams/TeamModelTest.php`

### Qué se cambió

Se añade `Team::reservedSlugs()`, que suma a la constante `RESERVED_SLUGS` lo que
haya en `config('teams.extra_reserved_slugs')`. Los tres puntos que consultaban la
constante (generación del slug, regla de validación y el test guardián) pasan a
usar el método.

> **Ojo al rebasear.** La primera versión de este seam sobrescribía
> `Team::otherRecordExistsWithSlug()`. En sluggable v4 (upstream #428) ese método
> **ya no existe en el trait**: se movió a `GenerateSlugAction`, y upstream metió el
> guard en `App\Support\ReservedSlugAwareGenerateSlugAction`, que recibe la lista por
> constructor. El seam es hoy **una sola línea** — pasarle `self::reservedSlugs()` en
> vez de `self::RESERVED_SLUGS` desde `Team::generateSlugAction()`.
>
> Es una divergencia que **falla en silencio**: si un rebase resuelve el conflicto de
> `Team.php` quedándose con la versión de upstream, el código compila, `reservedSlugs()`
> sigue ahí y no reserva nada. Le pasó al propio upstream (un equipo llamado "Admin"
> se llevó el slug `admin`). Tras cada rebase, comprueba que la lista que recibe la
> acción es el **método**, no la constante.

### Por qué

El slug de un equipo ocupa un **segmento de primer nivel** de la URL, así que un
addon que registra rutas propias tiene que poder reservar el suyo. Sin esto, un
equipo llamado "Sr Crm" toma el slug `sr-crm` y tapa `route('sr-crm.brand')` — el
PNG del logo que referencian las cabeceras de los emails.

Lo detecta el propio test guardián de upstream, `reserved slugs cover all
top-level route segments`, que compara los segmentos de todas las rutas contra la
lista. Con el addon enlazado fallaba con:

```
These route segments are missing from Team::reservedSlugs(): sr-crm
```

El addon empuja su slug desde `SrCrmServiceProvider::reserveRouteSlug()`. Es el
mismo patrón que `config('chat.extra_entities')`: clave neutra con default vacío
en el host, valores desde el addon.

### Cómo saber si sigue haciendo falta

```bash
php artisan test --filter='reserved slugs cover all top-level route segments'
```

Deja de hacer falta si upstream ofrece su propio punto de extensión para la lista,
o si el addon deja de registrar rutas web de primer nivel.

### Cómo deshacerla

Pasar `self::RESERVED_SLUGS` a `ReservedSlugAwareGenerateSlugAction`, volver a la
constante en `ValidTeamSlug` y en el test guardián, y borrar `reservedSlugs()`. Ojo:
entonces hay que meter `'sr-crm'` a mano en la constante, o el test guardián se pone
rojo otra vez.

### Alternativa descartada

Mover la ruta del addon a `assets/sr-crm/brand/{name}` (`assets` ya está
reservado), que no habría tocado el host. Se descartó porque cambia una URL
pública ya referenciada por los emails enviados: el logo se rompería en todo el
correo antiguo.

---

## 5. Tests de upstream adaptados al juego de campos y al alta del fork

**Fecha:** 2026-07-30 (ampliada 2026-08-04)
**Ficheros:** `tests/Arch/ArchTest.php`,
`tests/Feature/ActivityLog/*Test.php`, `tests/Feature/Api/V1/CompaniesApiTest.php`,
`tests/Feature/Auth/SocialiteLoginTest.php`, `tests/Feature/Chat/AllCustomFieldsViaChatTest.php`,
`tests/Feature/Chat/PendingActionDisplayDataTest.php`, `tests/Feature/Chat/RecordIncludesTest.php`,
`tests/Feature/Jobs/FetchFaviconForCompanyTest.php`,
`tests/Feature/Observers/CompanyObserverFaviconTest.php`, `tests/Feature/Onboarding/CreateTeamOnboardingTest.php`,
`tests/Feature/Public/PublicPagesTest.php`, `tests/Feature/Teams/InvitationUxTest.php`

### Por qué

Estos tests dan por hecho el CRM de upstream tal cual. Con el addon enlazado eso
deja de ser cierto por tres motivos, y solo por esos tres:

1. **Las secciones están activas.** Crear un equipo ya siembra la sección
   `general` de cada entidad, así que un `create()` a pelo choca contra
   `custom_field_sections_entity_type_code_tenant_id_unique`.
2. **El juego de custom fields cambia.** El addon siembra los suyos (`industry`
   como select, `website`…), desactiva `domains` de Compañías y redefine las
   opciones de `priority`.
3. **El alta es solo por invitación nominal.** `BlockRegistration` e
   `InvitationOnlySocialUserCreator` cierran el registro web y el social.

### Cómo se adaptaron

Sin debilitar ninguna aserción; donde se pudo se sustituyó por una más firme:

- `firstOrCreate` en vez de `create` para la sección `general`.
- Códigos propios del test (`api_industry`, `activity_website`) donde el código de
  upstream ya lo ocupa el addon.
- Reactivar `domains` en el arrange cuando lo que se prueba es otra cosa (el
  favicon, el aplanado multivalor, el formato de un link en la tarjeta).
- Buscar por `code` en vez de por `label`, que es identidad estable frente a los
  renombrados del addon.
- Leer la etiqueta de opción de la BD en vez de fijar `'High'`. Es la adaptación que
  más veces vuelve: `RecordIncludesTest` (upstream #429) llegó con el mismo `'High'`
  fijo y cayó igual, con `getKey() on null`. Cada test nuevo de upstream que toque
  `priority` va a necesitarla.
- `tests/Feature/AI/RecordSummaryServiceTest.php` salió de esta lista: upstream lo
  borró en #429 al desmontar el stack de resúmenes de ficha.
- Comprobar que están **todos** los códigos de los enums del host en vez de contar
  filas.
- Invertir las aserciones de alta abierta a alta cerrada, señalando en cada una el
  test del addon que cubre el camino con invitación.

`tests/Arch/ArchTest.php` suma `'SrJingles\SrCrm'` a los ignores del preset de
Laravel, junto al `'Relaticle\Chat'` que ya estaba y por el mismo motivo: el
preset solo reconoce `App\Http` como hogar válido de un API Resource.

### Cómo saber si sigue haciendo falta

Cada cambio lleva su comentario en el sitio explicando cuál de los tres motivos lo
provoca. Si el addon deja de desactivar `domains`, de redefinir `priority` o de
cerrar el alta, el cambio correspondiente sobra.

### Cómo deshacerla

`git diff upstream/main HEAD -- tests/` y revertir lo que corresponda.

**Ojo con la asimetría** (verificado el 2026-07-30 desenlazando el addon y
corriendo estos ficheros):

- Las adaptaciones de los motivos **1 y 2** —secciones y juego de campos— pasan
  con y sin addon: `firstOrCreate`, los códigos propios, buscar por `code` y leer
  la etiqueta de la BD son correctos también contra el CRM vainilla.
- Las del motivo **3** —alta cerrada— **solo** pasan con el addon enlazado, porque
  afirman el comportamiento del fork. Son 3: `SocialiteLoginTest`,
  `PublicPagesTest` y `InvitationUxTest`.

Esto no es una regresión nueva: la suite del host ya exigía el addon desde antes
(todo `tests/Feature/SrCrm/` referencia clases `SrJingles\…`, y
`InviteLinkBannerTest` ya afirmaba el alta cerrada). El entorno de referencia para
correr la suite es **con el addon**, según `docs/deploy.md`.

---

## 6. Horizon acotado a lo que cabe en el servidor

**Fecha:** 2026-08-06
**Ficheros:** `config/horizon.php`

### Qué se cambió

- Se **eliminan `supervisor-2` y `supervisor-3`** (de `defaults`, de `environments.production`
  y de `environments.local`). Eran copias **carácter por carácter** de `supervisor-1`: misma
  conexión, misma cola `default`, mismos parámetros.
- `supervisor-1` (producción): `maxProcesses` 10 → `env('HORIZON_DEFAULT_MAX', 6)`.
- `supervisor-imports` (producción): `maxProcesses` 15 → `env('HORIZON_IMPORTS_MAX', 4)` y
  `minProcesses` 3 → 1. En `defaults`, 20/5 → 4/1.
- `chat-supervisor` se queda como estaba (ya era configurable por `.env`).

### Por qué

Los tres supervisores idénticos no se repartían nada: con `balance => 'auto'` un solo
supervisor ya escala procesos según la carga. Lo único que hacían era **triplicar el suelo
de memoria**, porque cada uno mantiene su `minProcesses` vivo a todas horas.

Cada worker es un proceso PHP de ~90 MB. Las cuentas de antes:

| | workers | memoria |
| --- | --- | --- |
| En reposo | 7 | ~620 MB |
| En pico | 48 | ~4,3 GB |

El servidor tenía 1,9 GB. O sea que un pico de la cola de imports **no habría degradado el
sitio: habría invocado al OOM killer contra Postgres o php-fpm, en producción**. Que no
llegara a pasar es suerte, no diseño.

Se descubrió por el camino largo: los deploys fallaban con `Killed npm ci` y, al mirar quién
se comía la memoria, aparecieron ocho workers de Horizon ocupando ~700 MB en reposo.

Después del cambio: 3 workers en reposo (~270 MB) y 13 en pico (~1,2 GB), sobre un servidor
que además se subió a 4 GB.

### Cómo saber si sigue haciendo falta

Siempre que el servidor no tenga memoria de sobra. `maxProcesses × 90 MB` sumado de TODOS los
supervisores es el techo real; compáralo con `free -h`. Es la cuenta que no estaba hecha.

### Cómo deshacerla

Restaurar el `config/horizon.php` de upstream. Solo tiene sentido en un servidor con memoria
para 48 workers concurrentes (~4,3 GB solo de colas), y aun así los tres supervisores
duplicados seguirían sin aportar nada.

### Ajuste sin desplegar

Los topes salen del `.env`, así que se suben desde el panel de Forge sin tocar código:

```
HORIZON_DEFAULT_MAX=6
HORIZON_IMPORTS_MAX=4
HORIZON_CHAT_MAX=3
```

Tras cambiarlos: `php artisan horizon:terminate` (Supervisor relanza el proceso).
