/**
 * Standard shape for a structured UI data option — one entry in a closed const
 * set (select options, status maps, badge lookups, ...). Pairs with the
 * `resources/js/data/` folder: define shared value/label sets there as `as const`
 * arrays typed against this shape, then derive value unions and lookups from the
 * const instead of hand-maintaining parallel copies in each component.
 *
 * `value` and `label` are the required baseline; extend per set for extra fields
 * (e.g. `description`, or a domain flag) by declaring an interface that extends
 * `DataOption`.
 */
export interface DataOption<TValue = string> {
    /** The stable underlying value (form value, API value, or discriminant). */
    value: TValue;
    /** Human-readable label rendered in the UI. */
    label: string;
    /** Optional longer explanation for the option. */
    description?: string;
}
