# Read-only attributes are refused before validation

Core refuses a read-only attribute while it hydrates, but this package validates the document before hydration, so a body that also broke a rule answered `422` and hid the `403`. With `reject_read_only_attributes` on, the handler and the action invoker run the same check just before validation, so the refusal wins; core's own check during hydration then finds nothing left to refuse.
