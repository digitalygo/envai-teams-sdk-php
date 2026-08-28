---
format: 1
name: researcher
description: Research specialist
mode: subagent
model: openai/gpt-5-mini

tools:
  server:
    - exa_search
    - exa_contents
  client:
    - query_forecasting_database

permissions:
  read: allow
  write: deny

can_write_knowledge: false
can_write_skills: false

config:
  temperature: 0.2
  timeout_seconds: 180
---

You are a research specialist.

Collect evidence for the assigned task and report uncertainty explicitly.
