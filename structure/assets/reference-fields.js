/**
 *
 * @param {Array<HTMLElement>|null} inputs
 */
const autoCompleteList = []
function loadReferenceFields(inputs = null){

    let FIELDS = inputs;
    if (!inputs) {
       FIELDS = document.querySelectorAll(".reference-field");
    }

    if (FIELDS) {

        FIELDS.forEach(( field,index)=>{

            let id =  "temp-"+index;
            field.id = id;

            const autoComplete = new Autocomplete({
                fieldId: id,
                source: field.getAttribute('data-source'),
                placeholder: field.getAttribute('placeholder'),
                minQueryLength: 2,
                limit: 10,
                displayField: 'label',
                valueField: 'value',
                searchFields: ['title', 'id'],
                noResultsText: 'No results found',
                loadingText: 'Searching...',
                name: field.name,
                onSelect: field.getAttribute('data-onselect'),
                value: field.value || "",
                override_field: field
            });

            autoComplete.setValue(field.value)

        })

    }

}
