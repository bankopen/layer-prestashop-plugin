document.addEventListener('DOMContentLoaded', function(event) {
  var conditionsForm = document.getElementById('conditions-to-approve');
  // This is a <div> and not the button.
  var submitButton = document.getElementById('payment-confirmation');
  var newSubmitButton = document.createElement('button');
  var baseClass = 'btn btn-primary center-block ';
  newSubmitButton.id = 'layer-pay-button';

  if (!submitButton) {
    return;
  }

  var style =
    '\
    <style>\
    #layer-pay-button.shown{\
      display: block;\
    }\
    #layer-pay-button.not-shown{\
      display: none;\
    }\
    #layer-pay-button.shown+#payment-confirmation {\
      display:none !important;\
    }\
    </style>';

  newSubmitButton.innerHTML = 'Pay' + style;
  newSubmitButton.className = baseClass + 'not-shown';
  conditionsForm.insertAdjacentElement('afterend', newSubmitButton);

  var intervalId = null;

  // Pay button gets clicked
  newSubmitButton.addEventListener('click', function(event) {
    
	Layer.checkout(
	{
		token: layer_checkout_vars.token_id,
		accesskey: layer_checkout_vars.accesskey
	},
	function (response) {
		console.log(response)
		if(response !== null || response.length > 0 ){
			if(response.payment_id !== undefined){				
				var form = document.querySelector(
					'form[id=payment-form][action$="layerpayment/validation"]'
				);							

				let layer_pay_token_id = document.createElement("INPUT");
				Object.assign(layer_pay_token_id, {
				type: "hidden",
				name: "layer_pay_token_id",
				value: layer_checkout_vars.token_id
				});

				form.appendChild(layer_pay_token_id);
				
				let woo_order_id = document.createElement("INPUT");
				Object.assign(woo_order_id, {
				type: "hidden",
				name: "woo_order_id",
				value: layer_checkout_vars.orderid
				});

				form.appendChild(woo_order_id);
				
				let layer_order_amount = document.createElement("INPUT");
				Object.assign(layer_order_amount, {
				type: "hidden",
				name: "layer_order_amount",
				value: layer_checkout_vars.amount
				});

				form.appendChild(layer_order_amount);
				
				let layer_payment_id = document.createElement("INPUT");
				Object.assign(layer_payment_id, {
				type: "hidden",
				name: "layer_payment_id",
				value: response.payment_id
				});

				form.appendChild(layer_payment_id);
				
				let hash = document.createElement("INPUT");
				Object.assign(hash, {
				type: "hidden",
				name: "hash",
				value: layer_checkout_vars.hash
				});

				form.appendChild(layer_payment_id);

				submitButton.getElementsByTagName('button')[0].click();
			}	
		}			
		},
		function (err) {
		//alert(err.message);		
		});		
	});

  var parent = document.querySelector('#checkout-payment-step');

  parent.addEventListener(
    'change',
    function(e) {
      var target = e.target;
      var type = target.type;

      // We switch the buttons whenever a radio button (payment method)
      // or a checkbox (conditions) is changed
      if (
        (target.getAttribute('data-module-name') && type === 'radio') ||
        type === 'checkbox'
      ) {
        var selected = this.querySelector('input[data-module-name="layerpayment"]')
          .checked;

        if (selected) {
          newSubmitButton.className = baseClass + 'shown';
        } else {
          newSubmitButton.className = baseClass + 'not-shown';
        }

        // This returns the first condition that is not checked
        // and works as a truthy value
        newSubmitButton.disabled = !!document.querySelector(
          'input[name^=conditions_to_approve]:not(:checked)'
        );
      }
    },
    true
  );
});
