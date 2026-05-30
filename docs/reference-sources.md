# Reference Sources

This file records upstream repositories inspected while designing or implementing AgentGraph.

Reference repositories are local, read-only inputs. They are not package dependencies and must not be vendored into this repository.

## Laravel AI SDK

- Repository: <https://github.com/laravel/ai>
- Local path: `references/laravel-ai`
- Branch: `0.x`
- Inspected commit: `e819c296066b420cca4f726b7cbf2f2259aff46a`
- Date inspected: 2026-05-25
- Purpose:
  - Public agent contracts
  - Tool contracts
  - Sub-agent behavior
  - Prompt and stream integration
  - Conversation storage behavior
  - Testing patterns

## LangGraph

- Repository: <https://github.com/langchain-ai/langgraph>
- Local path: `references/langgraph`
- Branch: `main`
- Inspected commit: `1fcb768`
- Date inspected: 2026-05-30
- Purpose:
  - StateGraph validation
  - Multiple START edges
  - Pending writes
  - Command/Send
  - Interrupt resume
  - Checkpoint saver contracts

## Usage Rules

- Use these repositories for architecture research and source navigation only.
- Do not copy implementation code.
- Do not port Python internals line-by-line.
- Translate runtime guarantees into idiomatic Laravel and PHP abstractions.
- Actual integration with Laravel AI SDK must use the Composer dependency, not this local clone.
