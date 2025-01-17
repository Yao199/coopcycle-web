import React from 'react'
import { connect } from 'react-redux'
import _ from 'lodash'
import { withTranslation } from 'react-i18next'
import { ContextMenu, MenuItem, connectMenu } from 'react-contextmenu'

import { removeTasks } from '../redux/actions'

const UNASSIGN_SINGLE = 'UNASSIGN_SINGLE'
const UNASSIGN_MULTI = 'UNASSIGN_MULTI'

function _unassign(tasksToUnassign, removeTasks) {
  const tasksByUsername = _.groupBy(tasksToUnassign, task => task.assignedTo)
  _.forEach(tasksByUsername, (tasks, username) => removeTasks(username, tasks))
}

/**
 * The variable "trigger" contains the task that was right-clicked
 */
const DynamicMenu = ({ id, trigger, unassignedTasks, selectedTasks, tasksToUnassign, removeTasks, t }) => {

  const actions = []

  if (trigger) {

    const isAssigned = !_.find(unassignedTasks, unassignedTask => unassignedTask['@id'] === trigger.task['@id'])
    if (isAssigned) {
      actions.push(UNASSIGN_SINGLE)
    }

    if (selectedTasks.length > 0) {

      const isTriggerSelected = _.find(selectedTasks, selectedTask => selectedTask['@id'] === trigger.task['@id'])

      if (isTriggerSelected && tasksToUnassign.length > 0) {
        actions.push(UNASSIGN_MULTI)
      }
    }

  }

  return (
    <ContextMenu id={ id }>
      { actions.includes(UNASSIGN_SINGLE) && (
        <MenuItem onClick={ () => _unassign([ trigger.task ], removeTasks) }>
          { t('ADMIN_DASHBOARD_UNASSIGN_TASK', { id: trigger.task.id }) }
        </MenuItem>
      )}
      { actions.includes(UNASSIGN_MULTI) && (
        <MenuItem divider />
      )}
      { actions.includes(UNASSIGN_MULTI) && (
        <MenuItem onClick={ () => _unassign(tasksToUnassign, removeTasks) }>
          { t('ADMIN_DASHBOARD_UNASSIGN_TASKS_MULTI', { count: tasksToUnassign.length }) }
        </MenuItem>
      )}
      { actions.length === 0 && (
        <MenuItem disabled>
          Aucune action disponible
        </MenuItem>
      )}
    </ContextMenu>
  )
}

const Menu = connectMenu('dashboard')(DynamicMenu)

function mapStateToProps(state) {

  const tasksToUnassign =
      _.filter(state.selectedTasks, selectedTask =>
        !_.find(state.unassignedTasks, unassignedTask => unassignedTask['@id'] === selectedTask['@id']))

  return {
    unassignedTasks: state.unassignedTasks,
    selectedTasks: state.selectedTasks,
    tasksToUnassign,
  }
}

function mapDispatchToProps(dispatch) {
  return {
    removeTasks: (username, tasks) => dispatch(removeTasks(username, tasks))
  }
}

export default connect(mapStateToProps, mapDispatchToProps)(withTranslation()(Menu))
