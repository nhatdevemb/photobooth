-- New wizard: layout, capture, filter/beauty, template, review.
-- Preserve photographs and pinned artwork; only normalize the resumable UI step.
UPDATE sessions SET ui_step = CASE
    WHEN state IN ('RENDERING', 'REVIEW', 'FINALIZED', 'CLOSED') THEN 5
    WHEN configuration_locked = 1 THEN 2
    ELSE 1 END;
