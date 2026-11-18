# Membresías: activación y límites

La importación prepara conexiones y trabajo pendiente; **no garantiza que una tarea de sincronización termine correctamente**. La membresía administrada está desactivada por defecto. Active únicamente después de verificar versión exacta, parche, hashes, actualización de tablas, procesos PHP y aislamiento en una ventana sin escrituras concurrentes.

## Alcance

- Los participantes actuales del curso establecen el alcance administrado; docentes como propietarios y estudiantes como miembros según las capacidades de Microsoft 365.
- Solo las identidades con responsabilidad registrada pueden ser objeto de bajas o cambios administrados. Los propietarios y miembros ajenos a ese alcance no se adoptan automáticamente.
- Las generaciones y el historial conservan responsabilidad incluso ante errores o cambios de conexión. Nunca borre registros para forzar progreso.
- Una respuesta de Microsoft no equivale por sí sola a estado confirmado. Las operaciones y verificaciones pueden requerir varias ejecuciones; se conserva trabajo pendiente ante incertidumbre.

## Presupuestos y observación

| Ajuste | Predeterminado | Rango |
| --- | --- | --- |
| Filas del recorrido de respaldo por ejecución | 5 | 1–25 |
| Trabajos vencidos por ejecución | 1 | 1–2 |
| Peticiones de membresía compartidas por ejecución | 8 | 2–12 |

La tarea es elegible cada minuto; eso no promete una frecuencia real ni finalización. Los eventos encolan avisos y un cursor diario independiente recupera eventos perdidos. Los errores aplican espera progresiva. El límite de transporte es de 15 segundos por petición; con 12 peticiones son hasta 180 segundos, más trabajo local.

Los diagnósticos de pendientes indican ID de curso, estado, motivo y contadores acotados. Revise el resultado y el trabajo pendiente, no solo la frecuencia de cron. Un presupuesto insuficiente, un token vencido, contención, identidad ambigua o paginación extensa pueden impedir progreso. La finalización de grandes colas y la concurrencia con backend real no están demostradas por las pruebas offline de esta distribución.

## Enlaces opcionales

El descubrimiento de enlaces es independiente y también está desactivado por defecto. Lee publicaciones del canal General, su carpeta de material y carpetas de grabaciones verificables. No inventaría todas las reuniones del calendario ni modifica permisos de Microsoft. Las pestañas no garantizan que cada usuario tenga acceso al destino remoto.
