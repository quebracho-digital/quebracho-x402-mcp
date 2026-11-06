# Pendientes de Leo (quebracho-x402-mcp), 2026-11-05

Para retomar en otra sesión. `todo.txt` tiene el detalle y las fechas; esto es el resumen de lo que depende de Leo.

## Decisiones de Leo

1. **Job mode, estado `settling`.** ¿Agregamos un estado `settling` antes de `queued` en el store de jobs? Sirve para registrar el depósito antes de liquidarlo: si el proceso se cae entre la liquidación y el guardado del job, el reconciliador sabe qué buscar en la cadena. La librería ya tiene ese estado en su ledger; el job store todavía no lo usa. Recomendación: sí, ahora.
2. **Job mode, parámetros de entrada.** ¿Guardamos el nombre del tool y sus parámetros en el job? Sin ellos, un job que se cae a mitad no se puede reanudar y el comprador pierde lo pagado. Costo: el texto del comprador queda en disco hasta la entrega; hay que borrarlo al final.
3. **Job mode, hosts.** ¿La app corre en un solo servidor? El store de archivos (`app/src/Job`) solo sirve para un host. Para el hackathon, alcanza.
4. **W7, tope de gas.** `ChainFacilitator` recibe el tope por argumento, sin default en código (`null` = sin tope). La app no lo usa todavía (liquida con Circle/HttpFacilitator). ¿La app liquida on-chain con su gas wallet? Si sí, el valor va a un archivo de configuración y se pasa al constructor.
5. **AllowList de payTo en la demo.** `X402_PAYTO_ALLOW` en `app/.env` ya trae `0x7e510E6B4c3E2121CB64CD0686a4f241Df326510`. ¿Es tu wallet? Si no, cambiar el valor.
6. **OFAC, suscripción.** Suscribirte al correo de OFAC (GovDelivery, código `USTREAS_61`). El RSS se retiró. Es tarea tuya, no del código.
7. **OFAC, fuente.** La app baja la lista de un espejo de terceros (`0xB10C/ofac-sanctioned-digital-currency-addresses` en GitHub), no de OFAC. ¿Pasamos a bajarla directo de OFAC?

## Código sin commit (rama `feat/job-mode-foundations`)

- Job mode paso 4: `app/src/Job/` (`Job`, `JobStore`, `FileJobStore`) y sus tests. Lo hizo un subagente; 288 tests de la app OK, según su reporte. No lo revisé línea por línea.
- Cron diario de OFAC documentado en `README.md` raíz.
- Revertido el default de `maxGasCost` en `packages/x402/src/ChainFacilitator.php`; sin cambios netos en la librería.
- Versión de la librería y merge a `main`: decide Leo.
- Commit: nada se commitea sin tu orden.

## Pendientes en otros proyectos (ya en sus todo)

- Ombú (`ombu/todo.txt`): allowlist de payTo al importar y editar listados.
- Kalibang (`kalibang/todo.txt`): la misma allowlist en cualquier flujo que cobre con x402.

## Pendientes de la línea de hackathon (sin cambio hoy)

- Video v9, guion en `video/guion-v9.md`.
- Prueba en mainnet y smoke en testnet, una sola vez, cuando lo ordenes. Hasta entonces no se paga contra nodo real.
- Entrega del hackathon: 18/11, con cierre oficial 22/11 a las 18:00 CET.
