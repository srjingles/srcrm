# Despliegue (Laravel Forge)

Esta app es un fork de Relaticle (`srjingles/srcrm`) que monta el addon privado
`srjingles/sr-crm` (módulo de Proyectos, registro de tiempo, interacciones,
plantillas, API y MCP). El addon vive en su propio repositorio
(`git@github.com:srjingles/srcrm-addon.git`) y se versiona con tags semánticos.

## Modelo de ramas

| Rama | Rol | Composer / addon |
|------|-----|------------------|
| `srcrm-seams` | Integración. Aquí va el trabajo del fork; **se rebasea sobre upstream** para seguir a Relaticle. Historia volátil (force-push). | `composer.json/lock` se mantienen **limpios** (sin el addon). El cableado de dev lo inyecta `bin/dev-link.sh` y lo oculta con `skip-worktree`. |
| `production` | Lo que corre en vivo. La despliega Forge. Solo avanza hacia adelante. | Declara el addon de verdad: repositorio **VCS** a GitHub + `require srjingles/sr-crm: ^1.0`, con el `composer.lock` fijado al tag. |
| `main` | Espejo/base antigua de upstream. No se despliega. | — |

Por qué `production` separada: `srcrm-seams` se rebasea (reescribe historia), lo
que es incompatible con una rama de despliegue estable. `production` aísla a Forge
de esos force-push.

## Entorno de desarrollo (cableado del addon)

En local el addon se usa por **symlink**, no desde GitHub, vía el script del addon:

```bash
# cablear (por defecto host = ../srcrm)
/ruta/al/srcrm-addon/bin/dev-link.sh /ruta/al/srcrm
# revertir
/ruta/al/srcrm-addon/bin/dev-link.sh --unlink /ruta/al/srcrm
```

El script crea un repositorio `path` (symlink) bajo la misma clave `srcrm-addon`,
hace `require srjingles/sr-crm:@dev`, y marca `composer.json` y `composer.lock`
con `git update-index --skip-worktree` para que `srcrm-seams` no se ensucie.
También añade los assets publicados (`public/{css,js}/srjingles/`) al
`.git/info/exclude` del host.

> Si `git status` muestra `composer.json/lock` como modificados, es que falta el
> `skip-worktree`: vuelve a ejecutar `dev-link.sh`.

## Primer despliegue en Forge

1. **Acceso de Composer al repo privado del addon (token de GitHub).** Composer
   descarga `srjingles/sr-crm` por el `dist` zipball de la API de GitHub
   (`api.github.com/.../zipball/...`), que con un repo **privado** y sin autenticar
   devuelve **404**. Una clave SSH NO sirve aquí (es para `git clone`/`source`, no
   para la API). Solución: un **personal access token** con lectura del repo,
   configurado en Composer. Ver el detalle abajo en *Acceso al repo privado*.

2. **Crear el sitio** apuntando a `srjingles/srcrm`, rama **`production`**. Un
   ÚNICO site sirve los dos paneles (ver *Dominios y paneles*).

3. **`.env` de producción**. Mínimo imprescindible:

   ```env
   APP_ENV=production
   APP_DEBUG=false
   APP_KEY=base64:...          # php artisan key:generate si está vacío
   APP_URL=https://crm.srjingles.com
   APP_PANEL_DOMAIN=crm.srjingles.com
   SYSADMIN_DOMAIN=sysadmin.crm.srjingles.com

   # Base de datos: PostgreSQL OBLIGATORIO (ver aviso abajo)
   DB_CONNECTION=pgsql
   DB_HOST=127.0.0.1
   DB_PORT=5432
   DB_DATABASE=crm
   DB_USERNAME=forge
   DB_PASSWORD=...

   # Correo (necesario para verificación de email en el registro del CRM)
   MAIL_MAILER=...
   ```

   > ⚠️ **PostgreSQL es obligatorio.** `config/database.php` cae a **SQLite** por
   > defecto si falta `DB_CONNECTION`, y la migración `create_ai_credit_balances_table`
   > usa `ALTER TABLE ... ADD CONSTRAINT ... CHECK`, que SQLite no soporta: el deploy
   > falla en `migrate`. Crea la base de datos Postgres en Forge (Server → Database) y
   > rellena el bloque `DB_*`. Si el servidor se aprovisionó solo con MySQL, instala
   > PostgreSQL (recomendado: es lo que el proyecto usa y testea).

4. **Script de deploy** (Forge → Site → Deploy Script):

   ```bash
   cd /home/forge/<tu-sitio>
   git pull origin production
   composer install --no-dev --optimize-autoloader --no-interaction
   npm ci && npm run build
   php artisan migrate --force
   php artisan sr-crm:sync-custom-fields
   php artisan filament:assets        # publica los assets registrados por el addon
   php artisan filament:optimize
   php artisan optimize
   ```

   - `migrate --force` crea las tablas del addon (projects, time_entries,
     project_templates…). Las migraciones van empaquetadas; no hay que publicarlas.
   - `sr-crm:sync-custom-fields` siembra/actualiza los custom fields en cada equipo.
   - La config del addon usa la del paquete por defecto; solo si necesitas
     sobreescribirla: `php artisan vendor:publish --tag=srcrm-config`.

### Dominios y paneles

La app tiene **dos paneles Filament** servidos por **la misma aplicación**, que se
enrutan por **dominio** (no por path) en cuanto se definen estas env vars:

| Panel | Modelo de usuario | Dominio | Env var |
|-------|-------------------|---------|---------|
| `app` (CRM) | `User` + equipos (Jetstream) | `crm.srjingles.com` | `APP_PANEL_DOMAIN` |
| `sysadmin` | `SystemAdministrator` | `sysadmin.crm.srjingles.com` | `SYSADMIN_DOMAIN` |

Por eso se usa **un solo site** en Forge (mismo document root): el dominio principal
es `crm.srjingles.com` y `sysadmin.crm.srjingles.com` se añade como **alias** (Forge
los une en el `server_name` del mismo bloque Nginx). NO crear dos sites.

- **DNS**: registros A de ambos subdominios → IP del servidor.
- **SSL**: un certificado Let's Encrypt que incluya **ambos** dominios (cert SAN).
- **Sesiones**: deja `SESSION_DOMAIN` sin definir; cada panel gestiona su cookie.

### Usuarios iniciales

Cada panel tiene su propio flujo (modelos distintos):

- **sysadmin** — comando dedicado, en el servidor:
  ```bash
  cd /home/forge/crm.srjingles.com
  php artisan sysadmin:create        # interactivo: nombre, email, contraseña
  ```
  Luego entra en `https://sysadmin.crm.srjingles.com`.

- **CRM (`app`)** — **regístrate desde la web** en `https://crm.srjingles.com`. El
  registro está habilitado (`Features::registration()` + `->registration(...)`) y crea
  el usuario **y su equipo** correctamente. **No** uses `make:filament-user`: crearía
  un `User` sin equipo y rompería la tenencia multi-tenant.

  > El registro exige verificación de email (`Features::emailVerification()`). Si el
  > `.env` aún no tiene `MAIL_*` configurado, marca el email como verificado a mano:
  > ```bash
  > php artisan tinker --execute="\App\Models\User::where('email','TU_EMAIL')->update(['email_verified_at'=>now()]);"
  > ```

### Acceso al repo privado (token de GitHub)

`srjingles` es una **organización**, y el addon vive en el repo privado
`srjingles/srcrm-addon`. El token se crea **desde tu usuario personal** de GitHub
(no desde los ajustes de la organización), pero da acceso a un repo **de la
organización** — funciona porque eres miembro con acceso a ese repo.

1. **Crear el token (classic)** en tu cuenta personal:
   `https://github.com/settings/tokens/new`
   - *Note*: `Forge srcrm-addon` · *Expiration*: la que prefieras (apúntala para rotarlo).
   - *Select scopes*: marca **`repo`** (acceso a repos privados de orgs donde eres miembro).
   - **Generate token** y copia el valor (`ghp_…`, no se vuelve a mostrar).
   - Ruta manual: avatar → *Settings* → *Developer settings* → *Personal access tokens
     → Tokens (classic)*. **No** es el menú de Settings de la organización.

2. **(Solo si da 404)** la organización restringe los classic tokens. Permítelos /
   apruébalos en: Settings de la **organización** → *Third-party Access → Personal
   access tokens*.

3. **Configurar el token en el servidor** (como usuario `forge`, vía la terminal de
   Forge o `ssh forge@IP`):
   ```bash
   composer config --global --auth github-oauth.github.com ghp_TU_TOKEN
   ```
   Persiste en `~/.config/composer/auth.json` del usuario `forge` (fuera del repo;
   nunca lo commitees). Verifícalo sin redeploy:
   ```bash
   curl -s -H "Authorization: token ghp_TU_TOKEN" \
     https://api.github.com/repos/srjingles/srcrm-addon | head
   # debe mostrar "full_name": "srjingles/srcrm-addon", no un 404
   ```

> Alternativa fine-grained (permiso mínimo): token de tipo *fine-grained* con
> *Resource owner* = `srjingles`, *Only select repositories* = `srcrm-addon`,
> *Contents: Read-only*. Requiere que un admin de la org lo apruebe.

## Publicar una nueva versión

1. **Addon**: desde `srcrm-addon` en `main`, crea y sube un tag:
   ```bash
   git tag -a v1.x.y -m "..." && git push origin v1.x.y
   ```
2. **Subir el constraint** en `production` si cambias de minor/major (`^1.0` → `^1.1`):
   desmarca el `skip-worktree`, ajusta `composer.json`, `composer update srjingles/sr-crm`,
   commit del `composer.json/lock`, y vuelve a cablear dev con `dev-link.sh`.
   Si solo publicas un patch dentro del constraint, basta con regenerar el lock.
3. **Promover el host** a `production` (ver abajo) y dejar que Forge redespliegue.

## Promoción de `srcrm-seams` → `production`

`production` lleva su propio commit de Composer (el require VCS), que no existe en
`srcrm-seams`. Para traer los cambios de integración:

- **Avance normal** (sin rebase de seams):
  ```bash
  git checkout production && git merge srcrm-seams && git push
  ```
  No hay conflicto en `composer.json` mientras `srcrm-seams` no lo modifique.

- **Tras rebasear `srcrm-seams` sobre upstream** (su historia cambió): resetea
  `production` a la nueva base y reaplica el commit de Composer:
  ```bash
  git checkout production
  git reset --hard origin/srcrm-seams
  git cherry-pick <sha-del-commit "build(deploy): require srjingles/sr-crm">
  git push --force-with-lease
  ```

> **Mejora pendiente (opcional):** consolidar el require VCS en `srcrm-seams` para
> que `composer.json` sea idéntico en ambas ramas y `production` sea un espejo puro
> (sin commit propio). Eliminaría el `cherry-pick` tras cada rebase. Implica que
> `dev-link.sh` pase a ocultar solo la *diferencia* (path vs vcs) en vez del require
> completo.

## Rollback

`production` solo avanza, así que un rollback es apuntar Forge a un commit anterior
(o `git reset --hard <sha> && git push --force-with-lease` y redesplegar). El tag
del addon en el `composer.lock` garantiza que la versión del módulo es reproducible.
