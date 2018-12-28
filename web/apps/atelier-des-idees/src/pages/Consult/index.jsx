import React from 'react';
import { connect } from 'react-redux';
import PropTypes from 'prop-types';
import { ideaStatus } from '../../constants/api';
import { initConsultPage } from '../../redux/thunk/navigation';
import IdeaCardList from '../../containers/IdeaCardList';

class ConsultPage extends React.Component {
    componentDidMount() {
        this.props.initConsultPage();
    }

    render() {
        return (
            <div className="consult-page">
                <div className="l__wrapper">
                    <IdeaCardList mode="grid" status={ideaStatus.FINALIZED} withPaging={true} />
                </div>
            </div>
        );
    }
}

export default connect(
    null,
    { initConsultPage }
)(ConsultPage);