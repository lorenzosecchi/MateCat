/**
 * React Component for the warnings.

 */
var React = require('react');
const CommentsStore = require('../../stores/CommentsStore');
const SegmentsActions = require('../../actions/SegmentActions');
const CommentsConstants = require('../../constants/CommentsConstants');

class SegmentsCommentsIcon extends React.Component {

    constructor(props) {
        super(props);
        this.state = {
            comments: null
        };
        this.types = {sticky: 3, resolve: 2, comment: 1};

        this.updateComments = this.updateComments.bind(this);
    }

    updateComments(sid) {
        if ( _.isUndefined(sid) || sid === this.props.segment.sid ) {
            const comments = CommentsStore.getCommentsCountBySegment( this.props.segment.sid );
            this.setState( {
                comments: comments
            } );
        }
    }

    openComments(event) {
        event.stopPropagation();
        SegmentsActions.openSegmentComment(this.props.segment.sid);
        SegmentsActions.scrollToSegment(this.props.segment.sid);
        localStorage.setItem(MBC.localStorageCommentsClosed, false);
    }

    componentDidUpdate() {
        // const comments = CommentsStore.getCommentsBySegment(this.props.segment.sid);
    }

    componentDidMount() {
        this.updateComments(this.props.segment.sid);
        CommentsStore.addListener(CommentsConstants.ADD_COMMENT, this.updateComments);
        CommentsStore.addListener(CommentsConstants.STORE_COMMENTS, this.updateComments);
    }

    componentWillUnmount() {
        CommentsStore.removeListener(CommentsConstants.ADD_COMMENT, this.updateComments);
        CommentsStore.removeListener(CommentsConstants.STORE_COMMENTS, this.updateComments);
    }

    render() {
        //if is not splitted or is the first of the splitted group
        if ( (!this.props.segment.splitted || this.props.segment.sid.split('-')[1] === 1) && this.state.comments) {
            if (!this.props.segment.openComments) {
                let html;
                let rootClasses = ['mbc-comment-icon-button',
                    'txt'];
                if ( this.state.comments.total === 0 ) {
                    html = <span className="mbc-comment-notification mbc-comment-highlight-segment mbc-comment-highlight-invite">+</span>
                } else if ( this.state.comments.active > 0 ) {
                    rootClasses.push( 'has-object' );
                    html = <span className="mbc-comment-notification mbc-comment-highlight mbc-comment-highlight-segment">
                    {this.state.comments.active}
                </span>
                }

                return <div className={rootClasses.join( ' ' )} title="Add comment" onClick={(e) => this.openComments(e)}>
                    <span className="mbc-comment-icon icon-bubble2"/>
                    {html}
                </div>
            }
        } else {
            return null;
        }
    }
}

export default SegmentsCommentsIcon;
