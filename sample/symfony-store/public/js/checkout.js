/**
 * Checkout page behaviour: the "show payment methods" toggle and the JSON POST /checkout submit flow. The
 * payment method list itself is server-rendered (see templates/checkout/index.html.twig), not built here.
 * Identical to the plain-PHP and Laravel samples' copy of this file -- the JSON contract is the same across
 * all three.
 */
(function () {
  var HIDDEN_DESC = 'Off: shopper selects after redirect, on the Presto payment page';
  var SHOWN_DESC = 'On: shopper selects here, before paying';
  var HIDDEN_HINT = 'Enter the amount and description. You will choose how to pay on the next page.';
  var SHOWN_HINT = 'Enter the amount and description, then choose how you want to pay.';
  var HIDDEN_FOOTNOTE = 'Secure and encrypted';
  var SHOWN_FOOTNOTE = 'Secure and encrypted. Card details are entered on the Presto secure page next.';
  var HIDDEN_LABEL = 'Continue to Payment';
  var ERROR_FIELD_IDS = ['amountInRinggit', 'displayDesc', 'selectedPaymentMethod'];

  var form = document.getElementById('checkoutForm');
  var submitButton = document.getElementById('submitButton');
  var checkoutAlert = document.getElementById('checkoutAlert');
  var checkoutAlertMessage = document.getElementById('checkoutAlertMessage');
  var toggle = document.getElementById('showPaymentMethods');
  var toggleDesc = document.getElementById('toggleDesc');
  var methodSection = document.getElementById('methodSection');
  var checkoutHint = document.getElementById('checkoutHint');
  var submitButtonLabel = document.getElementById('submitButtonLabel');
  var checkoutFootnote = document.getElementById('checkoutFootnote');
  var amountInput = document.getElementById('amountInRinggit');

  function currentAmountText() {
    var value = parseFloat(amountInput.value);
    return isNaN(value) ? '0.00' : value.toFixed(2);
  }

  function applyToggle() {
    var showMethods = toggle.checked;
    methodSection.classList.toggle('hidden', !showMethods);
    toggleDesc.textContent = showMethods ? SHOWN_DESC : HIDDEN_DESC;
    checkoutHint.textContent = showMethods ? SHOWN_HINT : HIDDEN_HINT;
    checkoutFootnote.textContent = showMethods ? SHOWN_FOOTNOTE : HIDDEN_FOOTNOTE;
    submitButtonLabel.textContent = showMethods ? ('Pay RM ' + currentAmountText()) : HIDDEN_LABEL;
  }

  function clearErrors() {
    ERROR_FIELD_IDS.forEach(function (field) {
      var el = document.getElementById(field + 'Error');
      el.textContent = '';
      el.classList.add('hidden');
    });
    checkoutAlertMessage.textContent = '';
    checkoutAlert.classList.add('hidden');
  }

  function showFieldErrors(errors) {
    Object.keys(errors).forEach(function (field) {
      var el = document.getElementById(field + 'Error');
      if (el) {
        el.textContent = errors[field];
        el.classList.remove('hidden');
      }
    });
  }

  function showAlert(messageText) {
    checkoutAlertMessage.textContent = messageText;
    checkoutAlert.classList.remove('hidden');
  }

  function checkoutPayload() {
    var checkedMethod = document.querySelector('input[name="selectedPaymentMethod"]:checked');
    return {
      displayDesc: document.getElementById('displayDesc').value,
      amountInRinggit: amountInput.value,
      showPaymentMethods: toggle.checked,
      selectedPaymentMethod: checkedMethod ? checkedMethod.value : null
    };
  }

  toggle.addEventListener('change', applyToggle);
  amountInput.addEventListener('input', function () {
    if (toggle.checked) {
      submitButtonLabel.textContent = 'Pay RM ' + currentAmountText();
    }
  });

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    clearErrors();
    submitButton.disabled = true;

    fetch('/checkout', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(checkoutPayload())
    }).then(function (response) {
      return response.json().then(function (body) {
        return {status: response.status, body: body};
      });
    }).then(function (result) {
      if (result.status === 200) {
        window.location.href = result.body.paymentUrl || ('/return/' + result.body.txnRefNum);
        return;
      }
      if (result.status === 400) {
        showFieldErrors(result.body);
      } else {
        showAlert(result.body.message || 'Could not start payment.');
      }
      submitButton.disabled = false;
    }).catch(function () {
      showAlert('Could not reach the server.');
      submitButton.disabled = false;
    });
  });
})();
