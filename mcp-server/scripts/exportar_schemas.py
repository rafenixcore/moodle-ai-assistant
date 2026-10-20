"""Exporta los JSON Schemas de las tools del servidor MCP a
local-mcpchat/json/tools.json.

Fuente unica de verdad: el servidor MCP. Regenerar al cambiar tools:
    cd mcp-server && uv run python scripts/exportar_schemas.py
"""
import asyncio
import json
import os

from mcp import ClientSession, StdioServerParameters
from mcp.client.stdio import stdio_client

ROOT = os.path.dirname(os.path.abspath(__file__))        # mcp-server/scripts
MCP_DIR = os.path.dirname(ROOT)                          # mcp-server/
OUT = os.path.normpath(os.path.join(MCP_DIR, '..', 'local-mcpchat', 'json', 'tools.json'))


async def main() -> None:
    params = StdioServerParameters(
        command='uv',
        args=['run', '--project', MCP_DIR, 'moodle-mcp'],
        env={**os.environ})
    async with stdio_client(params) as (r, w):
        async with ClientSession(r, w) as s:
            await asyncio.wait_for(s.initialize(), timeout=30)
            tools = (await asyncio.wait_for(s.list_tools(), timeout=30)).tools
    entries = [{
        'name': t.name,
        'description': t.description or '',
        'parameters': t.inputSchema or {'type': 'object', 'properties': {}},
    } for t in tools]
    entries.sort(key=lambda e: e['name'])
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with open(OUT, 'w', encoding='utf-8') as f:
        json.dump(entries, f, ensure_ascii=False, indent=2)
    print(f'{len(entries)} tools -> {OUT}')


asyncio.run(main())
