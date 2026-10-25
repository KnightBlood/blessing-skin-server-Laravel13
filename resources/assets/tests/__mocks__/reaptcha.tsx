import React from 'react'
import type RealReaptcha from 'reaptcha'

type ReaptchaProps = React.ComponentProps<typeof RealReaptcha>

class Reaptcha extends React.Component<ReaptchaProps, {}> {
  execute() {
    this.props.onVerify('token')
  }

  reset() {}

  render() {
    return <></>
  }
}

export default Reaptcha
