# Seguridad y datos

**Use datos sintéticos y cuentas de laboratorio antes de considerar un despliegue real.** Este candidato no acredita una instalación nueva en producción ni el éxito de sincronización con Microsoft.

## Privilegios y efectos

La importación exige administrador del sitio y `moodle/site:config`; los POST verifican sesión y confirmación. El acceso al servidor para instalar el parche es privilegiado. Las tareas habilitadas pueden añadir o retirar membresías dentro del alcance administrado. Revise permisos de aplicación y consentimiento de Microsoft 365 con su responsable; no conceda permisos adicionales para sortear una guarda.

Los bloqueos del plugin no sustituyen una ventana sin actividad de herramientas externas. Evite importaciones, cambios de matrícula, conexiones y sincronizaciones paralelas. No use Teams administrados por SDS.

## Datos que deben protegerse

La ejecución puede almacenar IDs de usuarios y objetos Microsoft, conexiones curso/Team, generaciones, responsabilidad histórica, colas, cursores y URLs. Las previsualizaciones usan la sesión del administrador y el archivo cargado usa el almacenamiento temporal de Moodle. Los mensajes de error y diagnósticos pueden contener IDs de cursos; no publique logs ni previsualizaciones sin revisión.

Advertencia existente: `teamscsv/classes/privacy/provider.php` implementa `null_provider`, aunque las tablas y la descripción de privacidad reconocen almacenamiento de identificadores de participantes. Esta discrepancia no se corrige en el empaquetado. Requiere una evaluación específica de Privacy API, exportación/borrado y retención antes de un uso con datos personales; no interprete `null_provider` como ausencia de datos personales.

## Reportar un problema

No adjunte tokens, credenciales, CSV reales, IDs de tenant, capturas con personas ni respaldos a incidencias públicas. Para un informe inicial use pasos sintéticos, versiones y categorías del fallo. Si no existe un canal privado acordado, no publique detalles que permitan explotación ni datos sensibles; acuerde primero un medio seguro con quien mantenga la instalación.

## Límites de esta preparación

La selección local se inspeccionó buscando identificadores, direcciones, rutas privadas y material codificado, con revisión de coincidencias. No es una certificación exhaustiva ni sustituye la verificación independiente previa a publicar. El código y sus avisos se conservaron: no se relajaron guardas, hashes, permisos ni validaciones para facilitar la distribución.
