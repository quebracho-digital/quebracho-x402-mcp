# Pendientes de Leo (quebracho-x402-mcp), 2026-11-09

Resumen de lo que depende de Leo. `todo.txt` tiene el detalle y las fechas. Las decisiones de job mode, la allowlist de payTo, OFAC y la versión de la librería ya están tomadas y registradas en `DECISIONS.md` (D22 a D24).

## Para entregar el hackathon (plazo oficial: 2026-11-22 18:00 CET)

1. **Compra real en la demo (antes del 18/11).** Contra un listado de Ombú, con las wallets del hackathon de Tameion, con un tope de centavos y la clave solo en `app/.env.local`. Red elegida por Leo (2026-11-09): Base Sepolia, con `https://x402.org/facilitator` (no pide clave). Necesita: (a) que levantes la orden del 2026-11-05 de no pagar contra nodo real; (b) fondos de testnet en la wallet del comprador; (c) un listado de Ombú que cobre en Base Sepolia: hoy Ombú vende en Arc, así que hay que crear uno de prueba o comprar un servicio nuestro de esta app.
2. **Video nuevo (antes del 20/11).** Rehacerlo: la librería, la app Symfony con MCP, `x402_pay` y Ombú. Guion en `video/guion-v9.md` (local).
3. **Formulario.** Repo (este monorepo), video, y el párrafo actualizado (cifras: librería 670 tests, app 273, v0.4.1, `analyze_terms` super beta, compra real en mainnet por Ombú). Al enviar: verificar los números y crear el tag `hackathon-submission`.
4. **Prueba en mainnet de cada red soportada y smoke en testnet**, una sola vez, cuando lo ordenes.

## Decisiones abiertas

- **Tope de gas (W7).** ¿La app liquida on-chain con su propia gas wallet? Hoy liquida con Circle o con `HttpFacilitator`, así que `ChainFacilitator` y su tope no se usan. Si la respuesta es sí, el valor va a un archivo de configuración y se pasa al constructor.
- **Tag v0.4.2 de la librería**, al fusionar `quebracho-digital/x402#3` (correcciones de `CasperExact` tras la revisión de protocolo). Decide Leo.
- **Propuesta a la Fundación x402** (modo de trabajos): primero preguntar en su Slack o abrir un issue corto.

## Fechas

- Cada semana, y antes de cada release: refrescar la lista OFAC incluida en la librería (`bin/update-ofac.php`).
- 2026-11-19: Concordium se puede retomar (`git show b83b145` lo restaura); hasta entonces nada de Concordium en este repo.
- 2026-11-27: rotar `HF_TOKEN` (vence el cupón).

## Fuera de este repo

- Ombú: guardar el `payTo` esperado de cada listado y rechazar un 402 con otro; detector de inyección de instrucciones al importar listados.
- `x402_pay` pasa a `quebracho/x402 ^0.4` (ya hecho en Ombú).
