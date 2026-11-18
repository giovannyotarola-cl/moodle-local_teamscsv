# Licencia y atribuciones

TeamsCSV se distribuye bajo **GNU GPL versión 3 o posterior**, conforme a los avisos `SPDX-License-Identifier: GPL-3.0-or-later` conservados en sus fuentes. El texto íntegro de GPLv3 está en [LICENSE](LICENSE), sin modificaciones ni traducción sustitutiva. El usuario responsable confirmó expresamente que tiene permiso para publicar estas contribuciones; esa confirmación no cambia los derechos de terceros ni atribuye el copyright a la cuenta que aloja el repositorio.

## Microsoft 365: código de terceros

`patches/local_o365-managed-membership.patch` modifica código del plugin **Microsoft moodle-local_o365** e incluye contexto de sus fuentes. El README público de upstream declara:

> © Microsoft, Inc. Code for this plugin is licensed under the GPLv3 license.

El parche es una modificación para TeamsCSV, no una versión oficial de Microsoft. Añade aislamiento de membresías administradas y transporte acotado en tres archivos; se conserva sin cambios dentro de esta distribución del plugin `2026060407`. No se incluye el paquete completo de Microsoft 365. La atribución de upstream no se extiende a una afirmación de autoría de Microsoft sobre las contribuciones locales.

Upstream también reserva las marcas y logotipos de Microsoft y prohíbe su reutilización fuera del plugin. Esta distribución no incorpora logotipos ni concede derechos sobre marcas. Se conservan los avisos originales; los archivos sin cabecera individual no se consideran dominio público.

- Repositorio upstream: https://github.com/microsoft/moodle-local_o365
- README consultado: https://raw.githubusercontent.com/microsoft/moodle-local_o365/master/README.md
- Desarrollo y contribuciones upstream: https://github.com/Microsoft/o365-moodle

## Procedencia verificable

| Documento | Fuente pública | SHA-256 de los bytes consultados |
| --- | --- | --- |
| `LICENSE` | https://www.gnu.org/licenses/gpl-3.0.txt | `3972dc9744f6499f0f9b2dbf76696f2ae7ad8af9b23dde66d6af86c9dfb36986` |
| README upstream | URL indicada arriba; rama mutable `master` | `0653440cd1823746823780b4cce8274007602d4d932cd24dbd59ecc7c484b584` |

El README upstream se consultó para atribución, no como prueba de compatibilidad con su versión actual. La compatibilidad permanece limitada a `local_o365` **exactamente `2025040835`**, con los tres hashes del parche especificados en [INSTALACION.md](INSTALACION.md).

Esta versión `0.1.4-alpha` se entrega sin garantía, según GPLv3. Publicar el código permite registrar errores y proponer mejoras; no acredita éxito de sincronización, aptitud para producción ni una revisión independiente ya realizada.
