var style = {
  base: {
    color: '#32325d',
    lineHeight: '18px',
    fontFamily: '"Helvetica Neue", Helvetica, sans-serif',
    fontSmoothing: 'antialiased',
    fontSize: '16px',
    '::placeholder': {
      color: '#aab7c4'
    }
  },
  invalid: {
    color: '#fa755a',
    iconColor: '#fa755a'
  }
}

let errorElement

function handleError(result) {

  $('.btn-payment').removeClass('btn-payment__loading')
  $('.btn-payment').attr('disabled', false)

  errorElement.textContent = result.error.message
  errorElement.classList.remove('hidden')
}

function handleServerResponse(response, stripe, form, tokenElement) {

  if (response.error) {
    handleError(response)
  } else if (response.requires_action) {

    // Use Stripe.js to handle required card action
    stripe.handleCardAction(
      response.payment_intent_client_secret
    ).then(function(result) {
      if (result.error) {
        handleError(result)
      } else {
        tokenElement.setAttribute('value', result.paymentIntent.id)
        form.submit()
      }
    })

  } else {
    tokenElement.setAttribute('value', response.payment_intent)
    form.submit()
  }
}

export default function(form, options) {

  errorElement = document.getElementById('card-errors')

  const confirmPaymentRoute = options.confirmPaymentRoute || window.Routing.generate('order_confirm_payment')

  $('.btn-payment').attr('disabled', true)

  let stripeOptions = {}

  if (options.account) {
    stripeOptions = {
      ...stripeOptions,
      stripeAccount: options.account
    }
  }

  // @see https://stripe.com/docs/payments/payment-methods/connect#creating-paymentmethods-directly-on-the-connected-account
  const stripe = Stripe(options.publishableKey, stripeOptions)

  // TODO Check options are ok

  var elements = stripe.elements()

  var card = elements.create('card', { style, hidePostalCode: true })

  card.mount('#card-element')

  card.on('ready', function() {
    $('.btn-payment').attr('disabled', false)
  })

  card.addEventListener('change', function(event) {
    var displayError = document.getElementById('card-errors')
    if (event.error) {
      displayError.textContent = event.error.message
    } else {
      displayError.textContent = ''
    }
  })

  form.addEventListener('submit', function(event) {

    event.preventDefault()
    $('.btn-payment').addClass('btn-payment__loading')
    $('.btn-payment').attr('disabled', true)

    errorElement.classList.add('hidden')

    stripe.createPaymentMethod('card', card, {
      billing_details: {
        name: options.cardholderNameElement.value
      }
    }).then(function(result) {

      if (result.error) {
        handleError(result)
      } else {
        fetch(confirmPaymentRoute, {
          method: 'POST',
          credentials: 'include',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ payment_method_id: result.paymentMethod.id })
        }).then(function(result) {
          result.json().then(function(json) {
            handleServerResponse(json, stripe, form, options.tokenElement)
          })
        })
      }
    })
  })

}
