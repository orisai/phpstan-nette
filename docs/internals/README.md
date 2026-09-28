Maintainer documentation; the user-facing guide is ../README.md.

# Internals

Architecture, invariants, cache design, test layout and known limitations, for someone changing the code.

- [forms.md](forms.md) – form and component shape inference, the shape store and result-cache salt; ends with the
  library-wide notes (configuration guard, result-cache meta services, test layout, running the gates)
- [component.md](component.md) – component attachment and the `orisaiNette.component.*` rules
- [dic.md](dic.md) – DI container analysis: registry, receiver classes, rules, type inference, dead-code usage
- [latte.md](latte.md) – Latte template analysis: compile pipeline, cross-file model, narrowing, customs, discovery
- [latte-forms.md](latte-forms.md) – the bridge checking form control names in templates
