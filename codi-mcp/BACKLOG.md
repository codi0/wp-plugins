# Future Maintainability Backlog

This file records Codi MCP maintainability considerations that are worth revisiting as the surrounding WordPress and MCP ecosystem evolves. These are not current defects and do not require changes to the 0.6.0 architecture today.

## Direct export/download symmetry

Codi's inbound deployment path is deliberately direct: Codi issues a one-time HTTPS PUT capability and the machine holding the artifact streams the exact bytes directly to WordPress. Plugin and theme export currently use bounded chunked/base64 responses instead.

Revisit whether exports should gain a symmetric direct-download capability, for example a short-lived HTTPS GET capability backed by an immutable staged artifact. Only change this if there is a concrete operational need; do not add another transfer path merely for API symmetry.

## Layered MCP presentation

Codi currently presents selected abilities as direct MCP tools/resources/prompts. This preserves per-ability schemas, annotations, and clear risk semantics, and remains the preferred presentation while the catalogue is manageable.

If the exposed catalogue becomes demonstrably too large for MCP clients or agent tool selection, consider an alternative layered presentation built behind the existing `McpPresentation` boundary, such as discover/get-info/execute. Do not collapse the underlying WordPress abilities or governance model simply to reduce tool count.

## Adoption of future native WordPress abilities

WordPress Core and plugins are expected to add more native abilities over time. Review relevant native abilities as they actually ship rather than pre-emptively duplicating or targeting proposed APIs.

Adopt a native ability when it fully replaces Codi-owned functionality without weakening Codi's requirements. Keep a Codi facade where Codi still adds meaningful policy, transaction semantics, auditing, deployment/transfer behavior, multisite governance/routing, diagnostics, or other agent-facing safeguards.

The architectural rule remains: functionality should be exposed through WordPress Abilities first, with MCP and other consumers layered on top of that contract.
