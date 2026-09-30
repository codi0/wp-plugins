# Future Maintainability Backlog

This file records Codi MCP maintainability considerations that are worth revisiting as the surrounding WordPress and MCP ecosystem evolves. These are not current defects and do not require changes to the current architecture.

## Direct export/download symmetry

Codi's inbound deployment path is deliberately direct: Codi issues a one-time HTTPS PUT capability and the machine holding the artifact streams the exact bytes directly to WordPress. Plugin and theme export currently use bounded chunked/base64 responses instead.

Revisit whether exports should gain a symmetric direct-download capability, for example a short-lived HTTPS GET capability backed by an immutable staged artifact. Only change this if there is a concrete operational need; do not add another transfer path merely for API symmetry.

## Layered MCP presentation

Codi currently presents selected abilities as direct MCP tools/resources/prompts. This preserves per-ability schemas, annotations, and clear risk semantics, and remains the preferred presentation while the catalogue is manageable.

If the exposed catalogue becomes demonstrably too large for MCP clients or agent tool selection, consider an alternative layered presentation built behind the existing `McpPresentation` boundary, such as discover/get-info/execute. Do not collapse the underlying WordPress abilities or governance model simply to reduce tool count.

