class Checkout {

    /**
     * @param {HTMLElement} checkoutWrapperElement
     * @param {number} orderId
     * @param {Object} options
     */
    constructor(checkoutWrapperElement, orderId, options) {
        this.checkoutWrapperElement = checkoutWrapperElement;
        this.orderId = orderId;
        this.cartOptions = options;
        this.currentStep = options.step;
        this.summaryRefresh();
        this.#_addNavigations()
    }

    async summaryRefresh(){
        const section = this.checkoutWrapperElement.querySelector('#summary-section');
        if (section) {

            section.querySelector('#order-summary-content').style.display = "block";
            const response = await fetch(`/order/summary/auto-save/${this.orderId}`);
            const results = await response.json();

            if (results.status === true) {
                const order = results.order;

                // const updatables = [
                //     {subtotal: "Subtotal"},
                //     {shipping_amount: "Shipping"},
                //     {tax_amount: "Tax"},
                //     {discount_amount: "Discounts"},
                //     {adjustments: "Additional Summary"}
                // ];

                section.querySelector("#order-subtotal").textContent = this.#_getCurrencySymbol(order.currency) +
                    order.subtotal;
                section.querySelector("#order-shipping").textContent = this.#_getCurrencySymbol(order.currency)+
                    order.shipping_amount;
                section.querySelector("#order-tax").textContent = this.#_getCurrencySymbol(order.currency) +
                    order.tax_amount;
                section.querySelector("#order-total").textContent = this.#_getCurrencySymbol(order.currency)+
                    order.total_amount;
                section.querySelector('#order-summary-content').style.display = "none";
                section.querySelector("#order-summary").removeAttribute('style');
            }
        }
    }

    #_getCurrencySymbol(currencyCode, locale = 'en') {
        const parts = new Intl.NumberFormat(locale, {
            style: 'currency',
            currency: currencyCode
        }).formatToParts(0);

        return parts.find(part => part.type === 'currency').value;
    }

    /**
     *
     * @param {string} orderItemsSectionSelector
     * @param {object} options
     */
    orderItemActionInit(orderItemsSectionSelector, options = {})
    {
        this.orderItemsSection = this.checkoutWrapperElement.querySelector(orderItemsSectionSelector);
        this.orderItemOptions = options;

        if (this.orderItemsSection) {

            if (options.minusBtn && options.quantityField) {
                this.minusBtn = this.orderItemsSection.querySelectorAll(options.minusBtn);
            }

            if (options.plusBtn && options.quantityField) {
                this.plusBtn = this.orderItemsSection.querySelectorAll(options.plusBtn);
            }

            if (options.quantityField) {
                this.quantitieFields = this.orderItemsSection.querySelectorAll(options.quantityField);
            }

            if (options.unitPrice) {
                this.unitPrices = this.orderItemsSection.querySelectorAll(options.unitPrice)
            }

            if (options.itemTotal) {
                this.itemsTotals = this.orderItemsSection.querySelectorAll(options.itemTotal)
            }

            if (this.minusBtn.length === this.plusBtn.length && this.plusBtn.length === this.quantitieFields.length && this.plusBtn.length === this.unitPrices.length && this.plusBtn.length === this.itemsTotals.length) {
                this.#_addOrderItemsListen();
            }
        }
    }

    #_getNumericValue(priceString) {
        if (!priceString) return 0;

        return parseFloat(
            priceString.replace(/[^0-9.-]+/g, "")
        ) || 0;
    }

    #_updateItemNumbers(index, newCount) {

        const unitAmount = this.#_getNumericValue(this.unitPrices[index].value || this.unitPrices[index].textContent);
        if (typeof unitAmount === 'number' && unitAmount > 0) {
            const newTotal = unitAmount * newCount;
            const old = this.itemsTotals[index].value || this.itemsTotals[index].textContent;
            const currency = old[0] || "$";

            this.itemsTotals[index].value = currency + (newTotal).toString();
            this.itemsTotals[index].textContent = currency + (newTotal).toString()
        }

    }

    #_addOrderItemsListen()
    {
        this.minusBtn.forEach((item,index)=>{
            item.addEventListener('click',(e)=>{
                e.preventDefault();
                const quantityCount = parseInt(this.quantitieFields[index].value || this.quantitieFields[index].textContent);
                if (quantityCount > 0) {
                    this.quantitieFields[index].value = (quantityCount - 1).toString();
                    this.quantitieFields[index].textContent = (quantityCount - 1).toString();
                    this.#_updateItemNumbers(index, quantityCount - 1);
                    this.#_autoSaveInterval(this.orderItemOptions.autoSave)
                }
            })
        });

        this.plusBtn.forEach((item, index)=>{
            item.addEventListener('click', (e)=>{
                e.preventDefault();
                const quantityCount = parseInt(this.quantitieFields[index].value || this.quantitieFields[index].textContent);
                if (quantityCount > 0) {
                    this.quantitieFields[index].value = (quantityCount + 1).toString();
                    this.quantitieFields[index].textContent = (quantityCount + 1).toString();
                    this.#_updateItemNumbers(index, quantityCount + 1);
                    this.#_autoSaveInterval(this.orderItemOptions.autoSave)
                }
            })
        })
    }

    /**
     *
     * @param {number} autoSaveInterval
     */
    #_autoSaveInterval(autoSaveInterval) {
        let seconds = autoSaveInterval;
        if (autoSaveInterval <= 1000) {
            seconds = 1000;
            console.warn("auto save interval has been defaulted to 5 seconds");
        }

        setTimeout(async ()=>{

            const orderItems = Object.values(this.quantitieFields)  .map((item)=> {
                return {
                    id: item.dataset.order,
                    qty: item.value || item.textContent || item.dataset.qty || 0
                };
            });

            const response = await fetch('/order/items/auto-save',{
                method: "POST",
                headers:{
                    "Content-Type": "application/json"
                },
                body: JSON.stringify(orderItems)
            })
            const data = await response.json();
           await this.summaryRefresh();

        }, seconds)
    }

    #_addNavButton(name,btnType, btnId, btnClass, btnIcon, onclickCallback) {

        const btn = document.createElement('button');
        btn.type = btnType;
        btn.id = btnId;
        btn.className = `btn ${btnClass} btn-block`;
        btn.innerHTML = `${name} <i class="fas ${btnIcon}" style="margin-left: 8px;"></i>`;
        btn.onclick = (e) =>{
            onclickCallback.call(this, e);
        };

        if (this.checkoutWrapperElement.querySelector("#navigation")) {
            this.checkoutWrapperElement.querySelector("#navigation").append(btn);
        }
    }

    #_removeNavButton(btnId) {
        if (this.checkoutWrapperElement.querySelector("#navigation").querySelector(`${btnId}`)) {
            this.checkoutWrapperElement.querySelector("#navigation").querySelector(`${btnId}`).remove();
        }
    }

    #_addNavigations() {

        const index = this.#_currentStepIndex();

        console.log(index)
        if (index.total === 1) {
            ['continue','previous'].forEach((item)=>{
                this.#_removeNavButton(item)
            });
            this.#_addNavButton("Finish","button", "create", "btn-primary", "fa-save", this.#_saveOrder)
        }
        else {
            if (index.index + 1 < index.total) {
                this.#_addNavButton("Continue","button", "continue", "btn-primary", "fa-arrow-right", this.#_continueCartNavigation)
            }
            else {
                this.#_addNavButton("Previous","button", "previous", "btn-light", "fa-arrow-left", this.#_goBack)
                this.#_addNavButton("Finish","submit", "create", "btn-primary", "fa-save", this.#_saveOrder)
            }
        }
    }

    #_currentStepIndex() {

        let lastPart = window.location.href.split("/");
        lastPart = lastPart[lastPart.length - 1];
        const keys = Object.keys(this.cartOptions.steps);
        const index = keys.indexOf(lastPart);

        return {index, total: keys.length, steps: keys}
    }

    #_continueCartNavigation(e) {
        const state = this.#_currentStepIndex();
        const next = state.index + 1;
        if (next < state.total) {
            const step = state.steps[next];
            let parts = window.location.href.split("/");
            //remove last part
            parts = parts.slice(0, parts.length - 1);
            parts.push(step);
            const url = parts.join("/");
            window.history.pushState({ step: step }, "", url);
            window.location.assign(url);
        }
        console.log('click', state)
    }

    #_goBack(e) {
        const state = this.#_currentStepIndex();
        const next = state.index - 1;
        if (next >= 0) {
            const step = state.steps[next];
            let parts = window.location.href.split("/");
            //remove last part
            parts = parts.slice(0, parts.length - 1);
            parts.push(step);
            const url = parts.join("/");
            window.history.pushState({ step: step }, "", url);
            window.location.assign(url);
        }
        console.log('click', state)
    }

    #_saveOrder(e) {
        console.log(this.currentStep)
    }

    paymentGatewayInit() {
        this.paymentOptions = this.checkoutWrapperElement.querySelectorAll(".payment-option-card");

        if (this.paymentOptions) {
            this.paymentOptions.forEach((item)=>{
               item.addEventListener('click',(e)=>{
                   this.paymentOptions.forEach((it)=>{
                       it.classList.remove('selected')
                   })
                   this.#_selectPaymentGateway(e.target)
               })
            })
            this.paymentOptions.forEach((item)=>{
                if (item.classList.contains('selected')) {
                    this.selectedPaymentOption = item;
                }
            })
        }
        this.#_selectPaymentGateway(this.selectedPaymentOption);
    }

    async #_selectPaymentGateway(option) {

        if (!option.classList.contains('payment-option-card')) {
            option = option.closest(".payment-option-card")
        }

        option.classList.add('selected');
        this.selectedPaymentOption = option;
        const loader = this.checkoutWrapperElement.querySelector("#payment-form-loading");
        const formWrapper = this.checkoutWrapperElement.querySelector("#payment-form-content");

        loader.removeAttribute('style');
        formWrapper.setAttribute("style", "display: none;")

        const radioOption = option.querySelector("input[name='payment_gateway']");
        const paymentOptionValue = radioOption.value;

        const response = await fetch('/internal/payment/form/build?id='+paymentOptionValue);
        const results = await response.json();

        if (results.status === true) {
            formWrapper.innerHTML = results.html;
            loader.setAttribute("style", "display: none");
            formWrapper.removeAttribute("style");
            this.checkoutWrapperElement.querySelector("input[name='gateway_id']").value = paymentOptionValue;
        }
    }
}