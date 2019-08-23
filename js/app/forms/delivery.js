import React from 'react'
import { render } from 'react-dom'
import moment from 'moment'
import ClipboardJS from 'clipboard'

import AddressAutosuggest from '../components/AddressAutosuggest'
import DateTimePicker from '../widgets/DateTimePicker'
import TagsInput from '../widgets/TagsInput'

class DeliveryForm {
  disable() {
    $('#delivery-submit').attr('disabled', true)
    $('#loader').removeClass('hidden')
  }
  enable() {
    $('#delivery-submit').attr('disabled', false)
    $('#loader').addClass('hidden')
  }
}

function createAddressWidget(name, type, cb) {

  const widget = document.querySelector(`#${name}_${type}_address_streetAddress_widget`)
  const addresses = JSON.parse(widget.dataset.addresses)
  const value = document.querySelector(`#${name}_${type}_address_streetAddress`).value

  render(
    <AddressAutosuggest
      addresses={ addresses }
      address={ value }
      geohash={ '' }
      onAddressSelected={ (value, address) => {

        document.querySelector(`#${name}_${type}_address_streetAddress`).value = address.streetAddress
        document.querySelector(`#${name}_${type}_address_postalCode`).value = address.postalCode
        document.querySelector(`#${name}_${type}_address_addressLocality`).value = address.addressLocality
        document.querySelector(`#${name}_${type}_address_name`).value = address.name || ''
        document.querySelector(`#${name}_${type}_address_telephone`).value = address.telephone || ''

        document.querySelector(`#${name}_${type}_address_latitude`).value = address.geo.latitude
        document.querySelector(`#${name}_${type}_address_longitude`).value = address.geo.longitude

        let disabled = false

        if (address.id) {
          document.querySelector(`#${name}_${type}_address_id`).value = address.id
          disabled = true
        }

        document.querySelector(`#${name}_${type}_address_postalCode`).disabled = disabled
        document.querySelector(`#${name}_${type}_address_addressLocality`).disabled = disabled
        document.querySelector(`#${name}_${type}_address_name`).disabled = disabled
        document.querySelector(`#${name}_${type}_address_telephone`).disabled = disabled

        cb(type, address)

      } } />,
    widget
  )
}

function getDatePickerValue(name, type) {
  const datePickerEl = document.querySelector(`#${name}_${type}_doneBefore`)
  const timeSlotEl = document.querySelector(`#${name}_${type}_timeSlot`)

  if (timeSlotEl) {
    return $(`#${name}_${type}_timeSlot`).val()
  }

  return moment($(`#${name}_${type}_doneBefore`).val(), 'YYYY-MM-DD HH:mm:ss').format()
}

function getDatePickerKey(name, type) {
  const datePickerEl = document.querySelector(`#${name}_${type}_doneBefore`)
  const timeSlotEl = document.querySelector(`#${name}_${type}_timeSlot`)

  if (timeSlotEl) {
    return 'timeSlot'
  }

  return 'before'
}

function createDatePickerWidget(name, type, cb) {

  const datePickerEl = document.querySelector(`#${name}_${type}_doneBefore`)
  const timeSlotEl = document.querySelector(`#${name}_${type}_timeSlot`)

  if (timeSlotEl) {
    timeSlotEl.addEventListener('change', e => cb(type, e.target.value))
    return
  }

  new DateTimePicker(document.querySelector(`#${name}_${type}_doneBefore_widget`), {
    defaultValue: datePickerEl.value,
    onChange: function(date) {
      datePickerEl.value = date.format('YYYY-MM-DD HH:mm:ss')
      cb(type, date)
    }
  })
}

function createTagsWidget(name, type, tags) {
  new TagsInput(document.querySelector(`#${name}_${type}_tagsAsString_widget`), {
    tags,
    defaultValue: [],
    onChange: function(tags) {
      var slugs = tags.map(tag => tag.slug)
      document.querySelector(`#${name}_${type}_tagsAsString`).value = slugs.join(' ')
    }
  })
}

function serializeTaskForm(name, type) {

  let payload = {
    address: {
      streetAddress: $(`#delivery_${type}_address_streetAddress`).val(),
    },
    [getDatePickerKey(name, type)]: getDatePickerValue(name, type)
  }

  const lat = $(`#${name}_${type}_address_latitude`).val()
  const lng = $(`#${name}_${type}_address_longitude`).val()

  if (lat && lng) {

    const address = {
      ...payload.address,
      latLng: [
        parseFloat(lat),
        parseFloat(lng)
      ]
    }

    payload = {
      ...payload,
      address
    }
  }

  return payload
}

function serializeForm(name) {

  let payload = {
    pickup: serializeTaskForm(name, 'pickup'),
    dropoff: serializeTaskForm(name, 'dropoff'),
  }

  const el = document.querySelector(`form[name="${name}"]`)

  let storeId
  if (el.dataset.store) {
    storeId = el.dataset.store
  } else {
    storeId = $('#delivery_store').val()
  }

  if (storeId) {
    payload = {
      ...payload,
      store: `/api/stores/${storeId}`,
    }
  }

  return payload
}

export default function(name, options) {

  const el = document.querySelector(`form[name="${name}"]`)

  const form = new DeliveryForm()

  const onChange = options.onChange.bind(form)

  if (el) {

    // createAddressWidget(
    //   name,
    //   'pickup',
    //   (type, address) => onChange(serializeForm(name))
    // )
    createDatePickerWidget(
      name,
      'pickup',
      (type, date) => onChange(serializeForm(name))
    )

    // createAddressWidget(
    //   name,
    //   'dropoff',
    //   (type, address) => onChange(serializeForm(name))
    // )
    createDatePickerWidget(
      name,
      'dropoff',
      (type, date) => onChange(serializeForm(name))
    )

    $(`#${name}_store`).on('change', (e) => onChange(serializeForm(name)))

    const pickupTagsEl = document.querySelector(`#${name}_pickup_tagsAsString`)
    const dropoffTagsEl = document.querySelector(`#${name}_dropoff_tagsAsString`)

    if (pickupTagsEl && dropoffTagsEl) {
      $.getJSON(window.Routing.generate('admin_tags', { format: 'json' }), function(tags) {
        createTagsWidget(name, 'pickup', tags)
        createTagsWidget(name, 'dropoff', tags)
      })
    }

    new ClipboardJS('#copy', {
      text: function(trigger) {
        return document.getElementById('tracking_link').getAttribute('href')
      }
    })

    const packages = document.querySelector(`#${name}_packages`)

    if (packages) {

      const addPackageBtn = document.querySelector(`#${name}_packages_add`)

      $(`#${name}_packages_add`).click(function(e) {

        var list = $($(this).attr('data-target'))

        var counter = list.data('widget-counter') || list.children().length
        var newWidget = list.attr('data-prototype')

        newWidget = newWidget.replace(/__name__/g, counter)

        counter++
        list.data('widget-counter', counter)

        var newElem = $(newWidget)
        newElem.find('input[type="number"]').val(1)
        newElem.appendTo(list)
      })

      $(`#${name}_packages`).on('click', '[data-delete]', function(e) {
        const $target = $($(this).attr('data-target'))
        if ($target.length > 0) {
          $target.remove()
        }
      })
    }

  }

  return form
}
