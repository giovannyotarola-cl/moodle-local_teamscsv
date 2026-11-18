# Instalación controlada en un laboratorio

Esta guía no autoriza cambios en producción. Requiere administración de Moodle y del servidor, una copia de seguridad verificable y un entorno de prueba separado. No copie configuraciones ni datos personales al repositorio.

## Preparación

1. Compruebe Moodle mínimo `2025040800` y sus requisitos propios de PHP/base de datos. Use `local_o365` exactamente `2025040835`; una actualización posterior no está admitida por este adaptador.
2. Prepare Microsoft 365 y Course Sync con dirección **From Moodle to Teams**. Debe existir al menos un propietario del curso conectado a Microsoft; los participantes requieren asociaciones de identidad válidas.
3. Revise los permisos y el consentimiento de la aplicación Microsoft 365 con su administrador. El transporte necesita acceso a los grupos y sus propietarios/miembros; el descubrimiento necesita leer canales, mensajes y carpetas. Esta distribución no concede permisos ni certifica una configuración de tenant.
4. Mantenga desactivadas las opciones de membresía y descubrimiento automático. Aísle la ventana de instalación/importación: sin cron, refrescos web, tareas de caché ni cambios paralelos de conexiones o matrículas.

## Parche obligatorio

Aplique `patches/local_o365-managed-membership.patch` sobre la raíz del plugin `local/o365` de la versión exacta, sin ajustes manuales ni aplicación difusa. No contiene Microsoft 365 completo.

El artefacto de referencia del código tiene SHA-256:

```text
21584c98e162403426a2cd6c5ef1d178aae65cdbc5ee63138594c180b0c5fa2c
```

Después del parche, estos archivos relativos a `local/o365/classes/` deben tener los siguientes SHA-256:

| Archivo | SHA-256 obligatorio |
| --- | --- |
| `feature/coursesync/main.php` | `14b7e7894c79326fe9e03d0d63872e8957e3217787e5f376ca13af956358fcad` |
| `feature/coursesync/utils.php` | `cb804ac7a6009b72f5fb0cd8753808acda151892019183531ea80136eec3d8fa` |
| `rest/o365api.php` | `f498a96a82cde043f2de2b0f7bd2e21bda4d409a447a4b65198d2593028b764e` |

`teamscsv/classes/membership_guard.php` comprueba estos bytes y el contrato del transporte. No suprima la guarda ni cambie los hashes para aceptar una instalación diferente. Un fallo exige revisión de compatibilidad, no un bypass.

## Instalar el plugin

1. Copie únicamente `teamscsv/` a `<RAIZ_MOODLE>/local/teamscsv/`; no coloque toda esta distribución dentro de `local/teamscsv`.
2. Complete el procedimiento normal de actualización de Moodle para crear/verificar las tablas. Reinicie los procesos PHP según el procedimiento de su servidor para evitar código antiguo en memoria.
3. Verifique el aislamiento del parche y la versión instalada antes de reanudar tareas. No habilite membresías si falta una tabla, hay diferencias de hash o no puede demostrar el aislamiento.
4. Como administrador del sitio con `moodle/site:config`, abra la página del plugin, actualice la caché de Teams por el procedimiento controlado de Microsoft 365 y previsualice un CSV sintético.
5. Compare los IDs mostrados y confirme expresamente el alcance administrado. La previsualización es de un solo uso, ligada al usuario y caduca a los 15 minutos.
6. Evalúe por separado la activación de las tareas opcionales y sus pendientes; lea [MEMBERSHIP-SYNC.md](MEMBERSHIP-SYNC.md).

## CLI de caché

Desde la raíz de Moodle, `php local/teamscsv/cli/refresh_cache.php --help` muestra ayuda sin cargar Moodle ni contactar Microsoft. La ejecución real exige `--confirm-idle`, el usuario del servicio web y una sesión persistente. Actualiza únicamente la caché; puede contactar Microsoft. Su bloqueo **no** excluye los refrescos web ni las tareas de Microsoft 365: el operador debe impedir solapamientos.

## Reversión

No elimine tablas de registro ni historial de responsabilidad para «desbloquear» sincronizaciones. Desactivar membresías detiene trabajo opcional, pero no libera las exclusiones duraderas del parche. La reversión del plugin y del parche requiere un plan coordinado y revisión del estado; borrar archivos no revierte cambios ya realizados en Microsoft.
