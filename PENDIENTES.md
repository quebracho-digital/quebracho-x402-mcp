# Pendientes de Leo (quebracho-x402-mcp), 2026-11-11

Resumen de lo que depende de Leo. `todo.txt` tiene el detalle y las fechas. Las decisiones de job mode, la allowlist de payTo, OFAC y la versión de la librería ya están tomadas y registradas en `DECISIONS.md` (D22 a D24).

## Para entregar el hackathon (plazo oficial: 2026-11-22 18:00 CET)

1. **Compra real en la demo: hecha (2026-11-09).** En Arc testnet, con el facilitador de Circle y la wallet de Ombú como comprador y vendedor: `wisdom`, 0,01 USDC, tx `0x89c75dc8d426f6796caea2330204089e699d8dab09ca31bc22c83752c645c1a2`. Falta solo la captura en el video.
1b. **Parada global de pagos: hecha (2026-11-11).** `Quebracho\X402\Stop` (librería v0.5.0) cableada en vendedor y comprador; comando `app:x402:stop on|off|status` (D27).
2. **Video nuevo (antes del 20/11).** Rehacerlo: la librería, la app Symfony con MCP, `x402_pay` y Ombú. Guion en `video/guion-v9.md` (local).
3. **Formulario.** Repo (este monorepo), video, y el párrafo actualizado (cifras al 2026-11-11: app 278 tests, librería v0.5.0; el número de tests de la librería se verifica al enviar; `analyze_terms` super beta, compra real en mainnet por Ombú). Al enviar: verificar los números y crear el tag `hackathon-submission`.
4. **Prueba en mainnet de cada red soportada y smoke en testnet**, una sola vez, cuando lo ordenes.

## Decisiones abiertas

- **Tope de gas (W7).** ¿La app liquida on-chain con su propia gas wallet? Hoy liquida con Circle o con `HttpFacilitator`, así que `ChainFacilitator` y su tope no se usan. Si la respuesta es sí, el valor va a un archivo de configuración y se pasa al constructor.
- **Propuesta a la Fundación x402** (modo de trabajos): primero preguntar en su Slack o abrir un issue corto.

## Fechas

- Cada semana, y antes de cada release: refrescar la lista OFAC incluida en la librería (`bin/update-ofac.php`).
- 2026-11-19: Concordium se puede retomar (`git show b83b145` lo restaura); hasta entonces nada de Concordium en este repo.
- 2026-11-27: rotar `HF_TOKEN` (vence el cupón).

## Fuera de este repo

- Ombú: guardar el `payTo` esperado de cada listado y rechazar un 402 con otro; detector de inyección de instrucciones al importar listados.
- `x402_pay` pasa a `quebracho/x402 ^0.4` (ya hecho en Ombú); subirlo a `^0.5` y a `PriceList`/`SensitiveRules`/`Screening` de la librería es tarea de la sesión de Ombú.
