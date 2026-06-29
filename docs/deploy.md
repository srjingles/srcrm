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

1. **Acceso SSH al repo privado del addon.** Composer tendrá que clonar
   `srcrm-addon`. GitHub no permite reutilizar una *deploy key* en dos repos, así
   que lo más simple es copiar la clave SSH pública del servidor de Forge
   (Forge → Server → SSH) y añadirla a **tu cuenta de GitHub**
   (Settings → SSH and GPG keys). Da acceso a todos tus repos privados.
   *(Alternativa más cerrada: una cuenta "machine user" con acceso a ambos repos.)*

2. **Crear el sitio** apuntando a `srjingles/srcrm`, rama **`production`**.

3. **`.env` de producción**: `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY`,
   base de datos, correo, etc.

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
