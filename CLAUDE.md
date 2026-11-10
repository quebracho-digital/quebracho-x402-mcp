# CLAUDE.md — quebracho-x402-mcp

## Regla de oro
Nada de mentiras. Lo que no se verifica, no se afirma. Verificar antes de afirmar.

## Contexto del hub
- El hub vive en `~/.jcode/hub/`
- Fichas de proyectos: `~/.jcode/hub/proyectos/*.md`

## Al arrancar
1. Leer `CONTEXT.md` (local, fuera de git) y `DECISIONS.md`
2. Leer `~/Proyects/Quebracho/docs/inbox.md` (líneas de x402-mcp)
3. Buscar en remem: `remem search "x402-mcp decision pendiente"`

## Contexto del hackathon
- Hackathon Symfony AI & MCP
- Entrega propia: 20/11, límite: 22/11 18:00 CET
- Equipo "Ombu", Leo solo

## Reglas
- Repo público (leofishman/quebracho-x402-mcp), commits en inglés
- Librería PHP en packages/x402 (MIT)
- x402_pay 1.0.0-beta4 publicado en Drupal
- librería x402 v0.4.1 publicada en Packagist
- auth-capture en feat/auth-capture listo para v0.5.0 (solo Base)

## Auditoría
- bin/audit.sh completo antes de push
- skylos como herramienta de auditoría
- knowledge/hackathon-auditoria-previa-submit.md con checklist

## Reporte
Bitacora local con `## Hecho / ## Falló / ## Pendiente` + línea en `docs/inbox.md` del hub.

## Tokens
- HF_TOKEN en .env.local (no rotar, vale 20 USD)
- Nunca compartir claves privadas
