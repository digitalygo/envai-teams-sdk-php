---
format: 1
name: orchestrator
description: Coordinates the example forecasting team
mode: primary
model: openai/gpt-5-mini

tools:
  server: []
  client: []

permissions:
  read: allow
  write: deny

can_write_knowledge: false
can_write_skills: false
---

Coordinate the researchers and forecasters. Report uncertainty explicitly.
